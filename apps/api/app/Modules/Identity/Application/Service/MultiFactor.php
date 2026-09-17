<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\InvalidCredentials;
use App\Modules\Identity\Domain\Exception\MultiFactorRefused;
use App\Modules\Identity\Domain\Support\Totp;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Turning a second factor on, off, and proving it (ADR 0030).
 *
 * `users` has carried `mfa_secret_encrypted`, `mfa_enabled_at` and
 * `mfa_recovery_codes` since Phase 1, written by nothing. `/auth/me` has
 * reported `mfa_enabled` to every client for seven phases — always false,
 * always confidently — and `docs/06` says TOTP is "available from Phase 2".
 * PersonErasure scrubs all three columns (ADR 0022), which is how thoroughly
 * the product believed in a feature it did not have.
 *
 * Enrolment has two steps because a one-step version locks people out. A
 * secret written straight to `mfa_enabled_at` means the next sign-in demands a
 * code from an app that may have failed to scan the QR, mistyped the secret, or
 * been on a phone whose clock is wrong. So the secret is stored with
 * `mfa_enabled_at` still null — PENDING, exactly what two columns were always
 * for — and only a correct code from the app that holds it turns the factor on.
 */
final class MultiFactor
{
    /** How many recovery codes, and how long each is. */
    private const RECOVERY_CODES = 10;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AuthenticationService $auth,
    ) {}

    /**
     * Start enrolment: a secret, and the URI an authenticator app reads.
     *
     * Returns the secret in plain text. It is the ONE moment that is legitimate
     * — a secret nobody can read is a secret nobody can enrol — and it is the
     * reason this endpoint is behind an authenticated session and nothing else
     * returns it, ever.
     *
     * @return array{secret: string, uri: string}
     */
    public function begin(UserModel $user, Request $request): array
    {
        if ($user->hasMfaEnabled()) {
            throw new MultiFactorRefused(
                'Two-factor authentication is already on for this account.',
                ['refusal' => 'already_enabled'],
            );
        }

        $secret = Totp::generateSecret();

        // Encrypted with the application key, like every other secret this
        // product stores. A database copy without the key is not enough to
        // generate codes.
        $user->forceFill([
            'mfa_secret_encrypted' => Crypt::encryptString($secret),
            'mfa_enabled_at' => null,
            'mfa_recovery_codes' => null,
            'mfa_last_counter' => null,
        ])->save();

        // Not audited. Beginning enrolment is not a change to the account — it
        // can be abandoned, restarted, and abandoned again — and an audit log
        // that records intentions rather than effects is one people stop
        // reading. Confirming is the event.

        return [
            'secret' => $secret,
            'uri' => Totp::provisioningUri(
                $secret,
                (string) $user->email,
                (string) config('app.name'),
            ),
        ];
    }

    /**
     * Prove the app holds the pending secret, and turn the factor on.
     *
     * @return list<string> the recovery codes, in plain text, once
     */
    public function confirm(UserModel $user, string $code, Request $request): array
    {
        if ($user->hasMfaEnabled()) {
            throw new MultiFactorRefused(
                'Two-factor authentication is already on for this account.',
                ['refusal' => 'already_enabled'],
            );
        }

        $secret = $this->pendingSecret($user);

        if (! Totp::verify($secret, $code, now()->getTimestamp())) {
            throw new MultiFactorRefused(
                'That code does not match. Check the clock on the device running your authenticator app.',
                ['refusal' => 'code_mismatch'],
            );
        }

        $codes = $this->freshRecoveryCodes();

        $user->forceFill([
            'mfa_enabled_at' => now(),
            'mfa_recovery_codes' => array_map(self::hashRecoveryCode(...), $codes),
            // The enrolment code is spent by enrolling. It cannot then be
            // typed at a login prompt in the same period — which costs a person
            // who signs in elsewhere within thirty seconds one wait, and buys
            // that the code they have just read aloud to a screen-sharing call
            // is not a sign-in.
            'mfa_last_counter' => intdiv(now()->getTimestamp(), Totp::PERIOD),
        ])->save();

        $this->audit->record('auth.mfa_enabled', [], $request, actorUserId: (string) $user->getKey());

        // The caller of `revokeAllSessions` that ADR 0023 said was owed. Its
        // docblock has claimed since Phase 1 that it is called "on password
        // change, MFA change, role change and membership revocation", and it
        // was called by nothing. Turning on a second factor is exactly the
        // moment the sessions opened WITHOUT one should stop being trusted —
        // every device except this one, because signing somebody out of the
        // page they just enrolled on is the product punishing the right act.
        $this->auth->revokeOtherSessions($user, 'mfa_changed', $request);

        return $codes;
    }

    /**
     * Turn it off, with the password in hand.
     *
     * A session is not enough. Removing a factor is the one act in this feature
     * that makes an account weaker, and it is exactly what somebody does with a
     * laptop left unlocked — so it asks for something they would have to know
     * rather than something they merely have.
     */
    public function disable(UserModel $user, string $password, Request $request): void
    {
        if (! $user->hasMfaEnabled()) {
            throw new MultiFactorRefused(
                'Two-factor authentication is not on for this account.',
                ['refusal' => 'not_enabled'],
            );
        }

        if ($user->password_hash === null || ! Hash::check($password, $user->password_hash)) {
            $this->audit->record('auth.mfa_disable_failed', [
                'reason' => 'invalid_password',
            ], $request, actorUserId: (string) $user->getKey());

            throw new InvalidCredentials('That password is not correct.');
        }

        $user->forceFill([
            'mfa_secret_encrypted' => null,
            'mfa_enabled_at' => null,
            'mfa_recovery_codes' => null,
            'mfa_last_counter' => null,
        ])->save();

        $this->audit->record('auth.mfa_disabled', [], $request, actorUserId: (string) $user->getKey());

        $this->auth->revokeOtherSessions($user, 'mfa_changed', $request);
    }

    /**
     * The second half of a sign-in: open the challenge, check the code, issue
     * the session.
     *
     * The orchestration lives here rather than in the controller (which calls
     * one service, per docs/01 §3) and rather than in `AuthenticationService`
     * (which would then need this class back, and two services holding each
     * other is a cycle waiting to be resolved by a container at runtime).
     *
     * @return array{mfa_required: false, token: string, session: SessionModel, user: UserModel, membership: MembershipModel}
     */
    public function completeSignIn(string $challenge, string $code, Request $request): array
    {
        [$user, $membership] = $this->auth->openChallenge($challenge);

        $this->challenge($user, $code, $request);

        return $this->auth->issueSession($user, $membership, $request, viaMfa: true);
    }

    /**
     * A code at the login prompt: the app's, or one of the recovery codes.
     *
     * Everything here answers `InvalidCredentials`. A wrong code is a failed
     * sign-in, and "wrong code" must not be distinguishable from "wrong
     * recovery code" or from "already used" by somebody guessing — the message
     * differs only where the difference helps the legitimate owner and tells an
     * attacker nothing they did not already have.
     */
    public function challenge(UserModel $user, string $code, Request $request): void
    {
        if (! $user->hasMfaEnabled()) {
            throw new InvalidCredentials('These credentials do not match our records.');
        }

        if ($this->consumeRecoveryCode($user, $code)) {
            $this->audit->record('auth.mfa_recovery_used', [
                'codes_remaining' => count($user->mfa_recovery_codes ?? []),
            ], $request, actorUserId: (string) $user->getKey());

            return;
        }

        $secret = Crypt::decryptString((string) $user->mfa_secret_encrypted);
        $counter = intdiv(now()->getTimestamp(), Totp::PERIOD);

        if (! Totp::verify($secret, $code, now()->getTimestamp())) {
            $this->audit->record('auth.mfa_failed', [
                'reason' => 'code_mismatch',
            ], $request, actorUserId: (string) $user->getKey());

            throw new InvalidCredentials('That code is not right.');
        }

        // One-time means once. A valid code lives for up to ninety seconds
        // across the drift window, and without this the same six digits —
        // read over a shoulder, or captured by a phishing page a moment
        // earlier — sign in again inside that window.
        //
        // The cost is real and worth stating: a second sign-in within the same
        // thirty seconds has to wait for the next code. That is rare, and the
        // message says what to do rather than calling the code wrong.
        if ($user->mfa_last_counter !== null && $user->mfa_last_counter >= $counter) {
            $this->audit->record('auth.mfa_failed', [
                'reason' => 'code_reused',
            ], $request, actorUserId: (string) $user->getKey());

            throw new InvalidCredentials('That code has already been used. Wait for the next one.');
        }

        $user->forceFill(['mfa_last_counter' => $counter])->save();
    }

    /** @return list<string> */
    private function freshRecoveryCodes(): array
    {
        $codes = [];

        for ($index = 0; $index < self::RECOVERY_CODES; $index++) {
            // Grouped in fours, lower case, no ambiguous alphabet to invent:
            // these are read off a screen and typed by somebody who has lost
            // their phone and is not having a good day.
            $codes[] = Str::lower(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));
        }

        return $codes;
    }

    /**
     * SHA-256, not bcrypt.
     *
     * A recovery code is 12 random characters this product generated, not a
     * password somebody chose: there is nothing to brute force, so the slow
     * hash buys nothing and costs ten comparisons on every challenge.
     */
    private static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', $code);
    }

    private function consumeRecoveryCode(UserModel $user, string $code): bool
    {
        $stored = $user->mfa_recovery_codes ?? [];
        $candidate = self::hashRecoveryCode(Str::lower(trim($code)));
        $remaining = [];
        $used = false;

        foreach ($stored as $hash) {
            if (! $used && is_string($hash) && hash_equals($hash, $candidate)) {
                $used = true;

                continue;
            }

            $remaining[] = $hash;
        }

        if (! $used) {
            return false;
        }

        // Spent, not marked spent. A used recovery code that stays in the row
        // is a code somebody can try again.
        $user->forceFill(['mfa_recovery_codes' => $remaining])->save();

        return true;
    }

    private function pendingSecret(UserModel $user): string
    {
        if ($user->mfa_secret_encrypted === null) {
            throw new MultiFactorRefused(
                'There is nothing to confirm. Start again to get a new secret.',
                ['refusal' => 'no_pending_enrolment'],
            );
        }

        return Crypt::decryptString($user->mfa_secret_encrypted);
    }
}

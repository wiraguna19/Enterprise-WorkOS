<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\InvalidCredentials;
use App\Modules\Identity\Domain\Exception\NoActiveMembership;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Login, logout, and session lifecycle.
 *
 * See docs/06-auth-and-authorization.md §1.
 */
final class AuthenticationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly SessionLifetime $lifetime,
    ) {}

    /**
     * Sign in — or, for an account with a second factor, ask for it.
     *
     * The return is a discriminated pair rather than a nullable token, so a
     * caller cannot forget to look: `mfa_required` is true and there is a
     * challenge, or it is false and there is a session. Nothing in between.
     *
     * @return array{mfa_required: true, challenge: string}|array{mfa_required: false, token: string, session: SessionModel, user: UserModel, membership: MembershipModel}
     */
    public function login(
        string $email,
        string $password,
        Request $request,
        ?string $organizationId = null,
    ): array {
        $user = UserModel::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        // Constant-time: the same work and the same error whether the account
        // exists or the password is wrong. Anything else is a user enumeration
        // oracle (docs/06 §1).
        $passwordValid = $user !== null
            && $user->password_hash !== null
            && Hash::check($password, $user->password_hash);

        if (! $passwordValid) {
            Hash::make($password); // equalise timing on the unknown-user path
            $this->audit->record('auth.login_failed', [
                'email' => $email,
                'reason' => 'invalid_credentials',
            ], $request);

            throw new InvalidCredentials('These credentials do not match our records.');
        }

        if (! $user->isActive()) {
            $this->audit->record('auth.login_failed', [
                'email' => $email,
                'reason' => 'deactivated',
            ], $request);

            throw new InvalidCredentials('These credentials do not match our records.');
        }

        $membership = $this->resolveMembership($user, $organizationId);

        // A second factor stops here: the password was right, and that is not
        // yet a session (ADR 0030). What crosses back is a short-lived
        // challenge naming this user and this organization, encrypted with the
        // application key — a client cannot forge one, and cannot turn one into
        // anything except the code prompt it is for.
        if ($user->hasMfaEnabled()) {
            $this->audit->record('auth.mfa_challenged', [
                'email' => $email,
            ], $request, actorUserId: (string) $user->getKey());

            return [
                'mfa_required' => true,
                'challenge' => $this->issueChallenge($user, $membership),
            ];
        }

        return $this->issueSession($user, $membership, $request);
    }

    /**
     * The token that stands in for a half-finished sign-in.
     *
     * Encrypted rather than stored. A row per attempt would be a table to
     * write, index, prune and reason about for a value that is meaningless
     * after two minutes; Laravel's encrypter is authenticated, so a challenge
     * that has been edited does not decrypt at all. Two minutes is the whole
     * of its power: somebody who steals one has already supplied the password
     * it came from, and the code prompt throttles like the login it belongs to.
     */
    private function issueChallenge(UserModel $user, MembershipModel $membership): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => (string) $user->getKey(),
            'organization_id' => (string) $membership->organization_id,
            // `now()`, not `time()`: this product's clock is Laravel's, so a
            // test that travels forward finds an expired challenge and the
            // expiry is actually covered.
            'expires_at' => now()->getTimestamp() + 120,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Who a challenge names — checked again, not trusted.
     *
     * Public because `MultiFactor` owns the second half of the sign-in: it
     * opens the challenge, verifies the code, and asks for the session. That
     * keeps the dependency pointing one way (MultiFactor → this) instead of
     * two services each holding the other.
     *
     * @return array{0: UserModel, 1: MembershipModel}
     */
    public function openChallenge(string $challenge): array
    {
        try {
            /** @var array{user_id?: string, organization_id?: string, expires_at?: int} $payload */
            $payload = json_decode(Crypt::decryptString($challenge), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // One answer for a forged challenge, a corrupted one, and one from
            // an application key that has since been rotated. None of the three
            // is information a client is owed.
            throw new InvalidCredentials('This sign-in has expired. Please start again.');
        }

        if (($payload['expires_at'] ?? 0) < now()->getTimestamp()) {
            throw new InvalidCredentials('This sign-in has expired. Please start again.');
        }

        $user = UserModel::query()->find($payload['user_id'] ?? '');

        if ($user === null || ! $user->isActive()) {
            throw new InvalidCredentials('These credentials do not match our records.');
        }

        $membership = MembershipModel::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $payload['organization_id'] ?? '')
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();

        if ($membership === null) {
            // Membership is re-checked rather than trusted from the challenge:
            // two minutes is long enough for somebody to be offboarded between
            // the password and the code, and that is precisely the person this
            // whole feature exists to keep out.
            throw new NoActiveMembership('You do not have access to any organization.');
        }

        return [$user, $membership];
    }

    /**
     * @return array{mfa_required: false, token: string, session: SessionModel, user: UserModel, membership: MembershipModel}
     */
    public function issueSession(
        UserModel $user,
        MembershipModel $membership,
        Request $request,
        bool $viaMfa = false,
    ): array {
        // Read BEFORE the transaction and BEFORE the tenant resolver: how long
        // this session may live belongs to the organization being signed in to
        // (ADR 0028), not to the thirty days that were written here in Phase 1
        // and were the same for every tenant in the product.
        $lifetimeDays = $this->lifetime->daysFor((string) $membership->organization_id);

        return DB::transaction(function () use ($user, $membership, $request, $lifetimeDays, $viaMfa): array {
            $plainSecret = Str::random(48);

            $session = new SessionModel;
            $session->forceFill([
                'id' => SessionModel::newId(),
                'user_id' => $user->getKey(),
                'organization_id' => $membership->organization_id,
                'token_hash' => hash('sha256', $plainSecret),
                'name' => 'web',
                'abilities' => ['*'],
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'expires_at' => now()->addDays($lifetimeDays),
                // Signing in IS proving yourself, so the window for sensitive
                // acts opens here (ADR 0034) rather than making somebody type
                // their password twice in a row.
                'reauthenticated_at' => now(),
                'created_at' => now(),
            ])->save();

            $user->forceFill(['last_login_at' => now()])->save();

            // Bound to the organization, not merely describing it in metadata.
            // Signing in happens before the tenant resolver has run, so this
            // row was being written with a null `organization_id` — a platform
            // event — while naming the organization inside its own metadata.
            // The audit view is tenant-scoped, so every login in the product
            // was invisible to the organization it was a login to.
            $this->tenant->runFor(
                (string) $membership->organization_id,
                fn () => $this->audit->record('auth.login', [
                    'session_id' => $session->getKey(),
                    'session_lifetime_days' => $lifetimeDays,
                    'second_factor' => $viaMfa,
                ], $request, actorUserId: (string) $user->getKey()),
            );

            return [
                'mfa_required' => false,
                'token' => $session->getKey().'|'.$plainSecret,
                'session' => $session,
                'user' => $user,
                'membership' => $membership,
            ];
        });
    }

    public function logout(SessionModel $session, Request $request): void
    {
        $session->revoke('logout');

        $this->audit->record('auth.logout', [
            'session_id' => $session->getKey(),
        ], $request);
    }

    /**
     * Revoke every session for a user.
     *
     * This is the reason sessions are opaque and server-side rather than JWTs:
     * revocation is immediate (docs/06 §1).
     *
     * Its docblock said "called on password change, MFA change, role change and
     * membership revocation" from Phase 1. It is called by NOTHING, and none of
     * those four flows exists yet. Worse, it took a `$reason`, wrote it into the
     * audit metadata, and left the row itself unexplained — so the CHECK added
     * with `revoked_reason` in Phase 7 would have failed the first time anybody
     * called it. **A constraint found a defect in code no test ever ran.**
     * ADR 0023 recorded it as owed. **Turning a second factor on or off is its
     * first caller** (ADR 0030), through `revokeOtherSessions` below — the MFA
     * change in that list of four. The password change is still owed, and the
     * remaining two with it.
     */
    public function revokeAllSessions(string $userId, string $reason, ?Request $request = null): int
    {
        return $this->revoke($userId, $reason, null, $request);
    }

    /**
     * Revoke every session for a user EXCEPT the one asking.
     *
     * Which is what every "your credentials changed" flow actually wants. A
     * person who has just enrolled a second factor, on the page in front of
     * them, should not be signed out of it for their trouble — the sessions
     * that opened WITHOUT the factor are the ones that should stop being
     * trusted, and this device is not one of them.
     */
    public function revokeOtherSessions(UserModel $user, string $reason, ?Request $request = null): int
    {
        $current = $user->currentAccessToken();

        return $this->revoke(
            (string) $user->getKey(),
            $reason,
            $current instanceof SessionModel ? (string) $current->getKey() : null,
            $request,
        );
    }

    private function revoke(string $userId, string $reason, ?string $exceptSessionId, ?Request $request): int
    {
        $query = SessionModel::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at');

        if ($exceptSessionId !== null) {
            $query->whereKeyNot($exceptSessionId);
        }

        $count = $query->update(['revoked_at' => now(), 'revoked_reason' => $reason]);

        $this->audit->record('auth.session_revoked', [
            'user_id' => $userId,
            'reason' => $reason,
            'sessions_revoked' => $count,
        ], $request);

        return $count;
    }

    private function resolveMembership(UserModel $user, ?string $organizationId): MembershipModel
    {
        $query = MembershipModel::query()
            ->withoutGlobalScopes() // pre-tenant: this call is what CHOOSES the tenant
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at');

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $membership = $query->orderBy('joined_at')->first();

        if ($membership === null) {
            throw new NoActiveMembership('You do not have access to any organization.');
        }

        return $membership;
    }
}

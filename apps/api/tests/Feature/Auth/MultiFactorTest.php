<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Support\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * A second factor that exists (ADR 0030).
 *
 * `users` has carried `mfa_secret_encrypted`, `mfa_enabled_at` and
 * `mfa_recovery_codes` since Phase 1, written by nothing; `/auth/me` has
 * reported `mfa_enabled: false` to every client with complete confidence for
 * seven phases; `docs/06` says TOTP is "available from Phase 2"; and
 * `PersonErasure` scrubs all three columns. That is how thoroughly this product
 * believed in a feature it did not have.
 */
function secretOf(string $email): string
{
    return Crypt::decryptString(
        (string) DB::table('users')->where('email', $email)->value('mfa_secret_encrypted'),
    );
}

/** Enrol somebody fully, and hand back their recovery codes. */
function enrol(string $token, string $email): array
{
    test()->withToken($token)->postJson('/api/v1/auth/mfa')->assertOk();

    $codes = test()->withToken($token)
        ->postJson('/api/v1/auth/mfa/confirm', ['code' => Totp::at(secretOf($email), now()->getTimestamp())])
        ->assertOk()
        ->json('data.recovery_codes');

    // Past the period the enrolment code belongs to. Confirming SPENDS that
    // code (ADR 0030), so a sign-in inside the same thirty seconds is refused
    // as a reuse — which is the design working, and worth stating here rather
    // than discovering as a flake.
    test()->travel(Totp::PERIOD + 1)->seconds();

    return $codes;
}

it('does not turn the factor on until a code proves the app has the secret', function (): void {
    $token = $this->loginAs('sarah@acme.test');

    $begin = $this->withToken($token)->postJson('/api/v1/auth/mfa')->assertOk()->json('data');

    expect($begin['uri'])->toContain('otpauth://totp/');

    // The secret is stored and the factor is still OFF. A one-step enrolment
    // locks out everybody whose QR did not scan, whose typing slipped, or whose
    // phone clock is wrong — which is why two columns existed from Phase 1.
    $row = DB::table('users')->where('email', 'sarah@acme.test')->first();

    expect($row->mfa_secret_encrypted)->not->toBeNull()
        ->and($row->mfa_enabled_at)->toBeNull();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.user.mfa_enabled', false);
});

it('refuses a confirmation code that does not match the pending secret', function (): void {
    $token = $this->loginAs('sarah@acme.test');

    $this->withToken($token)->postJson('/api/v1/auth/mfa')->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'code_mismatch');

    expect(DB::table('users')->where('email', 'sarah@acme.test')->value('mfa_enabled_at'))->toBeNull();
});

it('turns the factor on, once, and hands over recovery codes', function (): void {
    $token = $this->loginAs('sarah@acme.test');
    $codes = enrol($token, 'sarah@acme.test');

    expect($codes)->toHaveCount(10);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.user.mfa_enabled', true);

    // Stored hashed. A support engineer reading the row cannot sign in as
    // anybody, which is the whole difference between a recovery code and a
    // second password.
    $stored = json_decode(
        (string) DB::table('users')->where('email', 'sarah@acme.test')->value('mfa_recovery_codes'),
        true,
    );

    expect($stored)->not->toContain($codes[0]);
});

it('ends every other session when the factor goes on, and keeps this one', function (): void {
    $phone = $this->loginAs('sarah@acme.test');
    $laptop = $this->loginAs('sarah@acme.test');

    enrol($laptop, 'sarah@acme.test');

    // The caller `revokeAllSessions` was documented as having since Phase 1 and
    // never had (ADR 0023). Sessions opened WITHOUT a second factor stop being
    // trusted the moment one is turned on — except the page it was turned on
    // from, because signing that one out punishes the right act.
    $this->withToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->withToken($laptop)->getJson('/api/v1/auth/me')->assertOk();

    expect(DB::table('sessions')->where('id', explode('|', $phone)[0])->value('revoked_reason'))
        ->toBe('mfa_changed');
});

it('asks for a code instead of issuing a session', function (): void {
    enrol($this->loginAs('sarah@acme.test'), 'sarah@acme.test');

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test',
        'password' => 'password',
    ])->assertOk();

    // The password was right, and that is not a session.
    $response->assertJsonPath('data.mfa_required', true)
        ->assertJsonMissingPath('data.token');
});

it('finishes the sign-in with the code from the app', function (): void {
    enrol($this->loginAs('sarah@acme.test'), 'sarah@acme.test');

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test',
        'password' => 'password',
    ])->assertOk()->json('data.challenge');

    $token = $this->postJson('/api/v1/auth/mfa/verify', [
        'challenge' => $challenge,
        'code' => Totp::at(secretOf('sarah@acme.test'), now()->getTimestamp()),
    ])->assertOk()->json('data.token');

    expect($token)->toBeString();

    $this->withToken((string) $token)->getJson('/api/v1/auth/me')->assertOk();
});

it('refuses the same code twice', function (): void {
    enrol($this->loginAs('sarah@acme.test'), 'sarah@acme.test');

    $code = Totp::at(secretOf('sarah@acme.test'), now()->getTimestamp());

    $firstChallenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test', 'password' => 'password',
    ])->json('data.challenge');

    $this->postJson('/api/v1/auth/mfa/verify', ['challenge' => $firstChallenge, 'code' => $code])
        ->assertOk();

    $secondChallenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test', 'password' => 'password',
    ])->json('data.challenge');

    // One-time means once. Without the stored counter the same six digits —
    // read over a shoulder, or captured by a phishing page a moment earlier —
    // sign in again for up to ninety seconds.
    $this->postJson('/api/v1/auth/mfa/verify', ['challenge' => $secondChallenge, 'code' => $code])
        ->assertUnauthorized();
});

it('spends a recovery code, and refuses it the second time', function (): void {
    $codes = enrol($this->loginAs('sarah@acme.test'), 'sarah@acme.test');

    $signIn = function () {
        return $this->postJson('/api/v1/auth/login', [
            'email' => 'sarah@acme.test', 'password' => 'password',
        ])->json('data.challenge');
    };

    $this->postJson('/api/v1/auth/mfa/verify', [
        'challenge' => $signIn(),
        'code' => $codes[0],
    ])->assertOk();

    $this->postJson('/api/v1/auth/mfa/verify', [
        'challenge' => $signIn(),
        'code' => $codes[0],
    ])->assertUnauthorized();

    $remaining = json_decode(
        (string) DB::table('users')->where('email', 'sarah@acme.test')->value('mfa_recovery_codes'),
        true,
    );

    expect($remaining)->toHaveCount(9);
});

it('refuses an expired challenge', function (): void {
    enrol($this->loginAs('sarah@acme.test'), 'sarah@acme.test');

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test', 'password' => 'password',
    ])->json('data.challenge');

    $this->travel(3)->minutes();

    $this->postJson('/api/v1/auth/mfa/verify', [
        'challenge' => $challenge,
        'code' => Totp::at(secretOf('sarah@acme.test'), now()->getTimestamp()),
    ])->assertUnauthorized();
});

it('refuses a challenge somebody made up', function (): void {
    enrol($this->loginAs('sarah@acme.test'), 'sarah@acme.test');

    $this->postJson('/api/v1/auth/mfa/verify', [
        'challenge' => 'not-a-real-challenge',
        'code' => '123456',
    ])->assertUnauthorized();
});

it('will not turn the factor off without the password', function (): void {
    $token = $this->loginAs('sarah@acme.test');
    enrol($token, 'sarah@acme.test');

    $this->withToken($token)
        ->deleteJson('/api/v1/auth/mfa', ['password' => 'not-the-password'])
        ->assertUnauthorized();

    expect(DB::table('users')->where('email', 'sarah@acme.test')->value('mfa_enabled_at'))
        ->not->toBeNull();

    $this->withToken($token)
        ->deleteJson('/api/v1/auth/mfa', ['password' => 'password'])
        ->assertNoContent();

    $row = DB::table('users')->where('email', 'sarah@acme.test')->first();

    expect($row->mfa_enabled_at)->toBeNull()
        ->and($row->mfa_secret_encrypted)->toBeNull()
        ->and($row->mfa_recovery_codes)->toBeNull();
});

it('refuses to enrol an account that is already enrolled', function (): void {
    $token = $this->loginAs('sarah@acme.test');
    enrol($token, 'sarah@acme.test');

    $this->withToken($token)->postJson('/api/v1/auth/mfa')
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'already_enabled');
});

it('replaces the recovery codes without taking the factor off', function (): void {
    $token = $this->loginAs('sarah@acme.test');
    $old = enrol($token, 'sarah@acme.test');

    $new = $this->withToken($token)
        ->postJson('/api/v1/auth/mfa/recovery-codes', ['password' => 'password'])
        ->assertOk()
        ->json('data.recovery_codes');

    expect($new)->toHaveCount(10)->and($new)->not->toBe($old);

    // The factor is still on, and the app still holds the same secret. The
    // first person to lose their list was told, by this product's own copy, to
    // turn two-factor off and set it up again — which leaves the account
    // unprotected for as long as it takes to re-scan a QR code, to solve a
    // problem that was never about the factor.
    expect(DB::table('users')->where('email', 'sarah@acme.test')->value('mfa_enabled_at'))
        ->not->toBeNull();

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test', 'password' => 'password',
    ])->json('data.challenge');

    // And the old list is dead.
    $this->postJson('/api/v1/auth/mfa/verify', ['challenge' => $challenge, 'code' => $old[0]])
        ->assertUnauthorized();
});

it('will not replace the recovery codes without the password', function (): void {
    $token = $this->loginAs('sarah@acme.test');
    $codes = enrol($token, 'sarah@acme.test');

    $this->withToken($token)
        ->postJson('/api/v1/auth/mfa/recovery-codes', ['password' => 'not-the-password'])
        ->assertUnauthorized();

    $challenge = $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test', 'password' => 'password',
    ])->json('data.challenge');

    // Refused means nothing changed: the list somebody already saved still
    // works. A half-applied refusal here would be the worst of both.
    $this->postJson('/api/v1/auth/mfa/verify', ['challenge' => $challenge, 'code' => $codes[0]])
        ->assertOk();
});

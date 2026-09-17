<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Support\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Unlocking somebody who has lost their phone (ADR 0031).
 *
 * The gap was found the only way it could be: an hour after two-factor
 * shipped, somebody enrolled, lost the authenticator entry and the recovery
 * codes on the same afternoon, and this product's answer was a row in `psql`.
 * In a real organization the answer is a help desk.
 */
/**
 * Enrol somebody, and hand back the session that did it.
 *
 * Returned, not discarded: once the factor is on, `loginAs` answers with a
 * challenge instead of a token, so a test that wants this person's session
 * afterwards has to keep the one they enrolled from — which is also the only
 * session enrolment leaves alive (ADR 0030).
 */
function enrolPerson(string $email): string
{
    $token = test()->loginAs($email);

    test()->withToken($token)->postJson('/api/v1/auth/mfa')->assertOk();

    $secret = Crypt::decryptString(
        (string) DB::table('users')->where('email', $email)->value('mfa_secret_encrypted'),
    );

    test()->withToken($token)
        ->postJson('/api/v1/auth/mfa/confirm', ['code' => Totp::at($secret, now()->getTimestamp())])
        ->assertOk();

    return $token;
}

/** Their membership in Acme — the tenant every test here acts in. */
function membershipIdOf(string $email): string
{
    return (string) DB::table('memberships')
        ->join('users', 'users.id', '=', 'memberships.user_id')
        ->where('users.email', $email)
        // Pinned to Acme on purpose: one test below gives Sarah a second
        // membership, and an unpinned lookup would start answering with the
        // Globex row halfway through the file.
        ->where('memberships.organization_id', '01900000-0000-7000-8000-0000000000ac')
        ->value('memberships.id');
}

it('lets an administrator take a lost second factor off', function (): void {
    enrolPerson('sarah@acme.test');

    $this->withToken($this->loginAs('rina@acme.test'))
        ->deleteJson('/api/v1/people/'.membershipIdOf('sarah@acme.test').'/mfa')
        ->assertNoContent();

    $row = DB::table('users')->where('email', 'sarah@acme.test')->first();

    expect($row->mfa_enabled_at)->toBeNull()
        ->and($row->mfa_secret_encrypted)->toBeNull()
        ->and($row->mfa_recovery_codes)->toBeNull();

    // And the password alone signs them in again, which is the whole point.
    // A session in the answer rather than a challenge: the successful payload
    // carries no `mfa_required` at all, so asserting on that key would pass
    // against a response shape that does not exist.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test', 'password' => 'password',
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);
});

it('leaves the sessions of the person it unlocks alone', function (): void {
    $hers = enrolPerson('sarah@acme.test');

    $this->withToken($this->loginAs('rina@acme.test'))
        ->deleteJson('/api/v1/people/'.membershipIdOf('sarah@acme.test').'/mfa')
        ->assertNoContent();

    // She did nothing wrong. Signing her out of everything on the day she is
    // already locked out would be the product kicking somebody who is down.
    $this->withToken($hers)->getJson('/api/v1/auth/me')->assertOk();
});

it('refuses an administrator their own factor', function (): void {
    $rina = enrolPerson('rina@acme.test');

    // The self-service path asks for the password precisely because removing a
    // factor is what somebody does with a laptop left unlocked. An
    // administrator who could use this on themselves would walk around that
    // with one click.
    $this->withToken($rina)
        ->deleteJson('/api/v1/people/'.membershipIdOf('rina@acme.test').'/mfa')
        ->assertForbidden();

    expect(DB::table('users')->where('email', 'rina@acme.test')->value('mfa_enabled_at'))
        ->not->toBeNull();
});

it('refuses somebody without the permission to take access away', function (): void {
    enrolPerson('sarah@acme.test');

    $this->withToken($this->loginAs('maya@acme.test'))
        ->deleteJson('/api/v1/people/'.membershipIdOf('sarah@acme.test').'/mfa')
        ->assertForbidden();
});

it('records the act in every organization the person belongs to', function (): void {
    // Gil is in Globex only, so the interesting case has to be built: the
    // audit view is tenant-scoped, and an event written only where the
    // administrator stands is invisible to the OTHER organization that also
    // relied on this factor.
    enrolPerson('sarah@acme.test');

    $sarah = DB::table('users')->where('email', 'sarah@acme.test')->value('id');

    DB::table('memberships')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => '01900000-0000-7000-8000-0000000000b0',
        'user_id' => $sarah,
        'status' => 'active',
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken($this->loginAs('rina@acme.test'))
        ->deleteJson('/api/v1/people/'.membershipIdOf('sarah@acme.test').'/mfa')
        ->assertNoContent();

    $organizations = DB::table('audit_logs')
        ->where('event', 'auth.mfa_revoked_by_admin')
        ->pluck('organization_id')
        ->all();

    expect($organizations)->toHaveCount(2)
        ->and($organizations)->toContain('01900000-0000-7000-8000-0000000000b0');
});

it('says nothing to unlock when there is nothing on', function (): void {
    $this->withToken($this->loginAs('rina@acme.test'))
        ->deleteJson('/api/v1/people/'.membershipIdOf('sarah@acme.test').'/mfa')
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'not_enabled');
});

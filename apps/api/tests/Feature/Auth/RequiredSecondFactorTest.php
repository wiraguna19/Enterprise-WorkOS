<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Support\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * An organization that requires a second factor of everybody (ADR 0033).
 *
 * `docs/06` has promised this since Phase 1 — "enforceable per organization
 * from Phase 7" — and ADR 0030 built the mechanism while leaving the policy
 * owed. The shape of the policy is the whole decision: refusing the SIGN-IN
 * would lock out everybody who had no warning, including the administrator who
 * flipped the switch, who has no factor either at that moment.
 */
function requireMfa(bool $required = true): void
{
    DB::table('organizations')->where('slug', 'acme')->update(['require_mfa' => $required]);
}

it('holds somebody without a factor at the enrolment screen, signed in', function (): void {
    $token = $this->loginAs('sarah@acme.test');

    requireMfa();

    // Signed in: she can say who she is, and the answer tells the interface
    // where to take her.
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.organization.requires_second_factor', true)
        ->assertJsonPath('data.user.mfa_enabled', false);

    // And confined: everything else is refused, with a code an interface can
    // branch on and a sentence a person can act on.
    $this->withToken($token)->getJson('/api/v1/work-items')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.mfa_required');

    // The way out is open — which is the point of confining rather than
    // refusing the session.
    $this->withToken($token)->postJson('/api/v1/auth/mfa')->assertOk();
});

it('lets the confined session finish enrolling and carry on', function (): void {
    $token = $this->loginAs('sarah@acme.test');

    requireMfa();

    $this->withToken($token)->postJson('/api/v1/auth/mfa')->assertOk();

    $secret = Crypt::decryptString(
        (string) DB::table('users')->where('email', 'sarah@acme.test')->value('mfa_secret_encrypted'),
    );

    $this->withToken($token)
        ->postJson('/api/v1/auth/mfa/confirm', ['code' => Totp::at($secret, now()->getTimestamp())])
        ->assertOk();

    // The same session, unconfined the moment the factor exists. Nothing was
    // signed out, and nobody had to sign in again.
    $this->withToken($token)->getJson('/api/v1/work-items')->assertOk();
});

it('signs nobody out when the policy goes on', function (): void {
    $token = $this->loginAs('sarah@acme.test');

    requireMfa();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    expect(DB::table('sessions')->where('id', explode('|', $token)[0])->value('revoked_at'))
        ->toBeNull();
});

it('leaves an organization that does not require it alone', function (): void {
    requireMfa();

    // Gil is in Globex, which requires nothing. A policy that leaked across
    // tenants would be the worst possible version of this feature.
    $this->withToken($this->loginAs('gil@globex.test'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.organization.requires_second_factor', false);
});

it('refuses to let somebody turn their factor off while it is required', function (): void {
    $token = $this->loginAs('sarah@acme.test');

    $this->withToken($token)->postJson('/api/v1/auth/mfa')->assertOk();

    $secret = Crypt::decryptString(
        (string) DB::table('users')->where('email', 'sarah@acme.test')->value('mfa_secret_encrypted'),
    );

    $this->withToken($token)
        ->postJson('/api/v1/auth/mfa/confirm', ['code' => Totp::at($secret, now()->getTimestamp())])
        ->assertOk();

    requireMfa();

    // Allowing it would sign her out of nothing and confine her a millisecond
    // later: a loop rather than a feature.
    $this->withToken($token)
        ->deleteJson('/api/v1/auth/mfa', ['password' => 'password'])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'required_by_organization');
});

it('counts the people a switch would hold, before it is flipped', function (): void {
    $settings = $this->withToken($this->loginAs('rina@acme.test'))
        ->getJson('/api/v1/organization/settings')
        ->assertOk()
        ->json('data');

    expect($settings['require_mfa'])->toBeFalse()
        ->and($settings['people_without_mfa'])->toBeGreaterThan(0);
});

it('needs the settings permission to require anything of anybody', function (): void {
    $this->withToken($this->loginAs('sarah@acme.test'))
        ->patchJson('/api/v1/organization/settings/mfa-policy', ['require_mfa' => true])
        ->assertForbidden();

    expect(DB::table('organizations')->where('slug', 'acme')->value('require_mfa'))->toBeFalse();
});

it('turns the requirement back off', function (): void {
    $admin = $this->loginAs('rina@acme.test');

    $this->withToken($admin)
        ->patchJson('/api/v1/organization/settings/mfa-policy', ['require_mfa' => true])
        ->assertOk()
        ->assertJsonPath('data.require_mfa', true);

    // Rina has no factor either — the administrator who flips the switch is
    // confined by it exactly like everybody else, which is why the switch has
    // to be reachable from a confined session. It is not: turning it back off
    // is refused until she enrols, and that is the honest consequence of a
    // policy with no exceptions.
    $this->withToken($admin)
        ->patchJson('/api/v1/organization/settings/mfa-policy', ['require_mfa' => false])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.mfa_required');
});

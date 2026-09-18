<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Service\RecentAuthentication;
use Illuminate\Support\Facades\DB;

/**
 * "Prove it again" for the acts that deserve it (ADR 0034).
 *
 * A session lasts up to ninety days and an idle window can be hours. Both are
 * right for reading work and moving a card; neither is an answer to somebody
 * sitting down at an unlocked laptop and erasing a colleague.
 */
function staleSession(string $token): void
{
    DB::table('sessions')
        ->where('id', explode('|', $token)[0])
        ->update(['reauthenticated_at' => now()->subMinutes(RecentAuthentication::WINDOW_MINUTES + 1)]);
}

function membershipOf(string $email): string
{
    return (string) DB::table('memberships')
        ->join('users', 'users.id', '=', 'memberships.user_id')
        ->where('users.email', $email)
        ->where('memberships.organization_id', '01900000-0000-7000-8000-0000000000ac')
        ->value('memberships.id');
}

it('lets a fresh sign-in through without asking twice', function (): void {
    // Signing in IS proving yourself. Asking for the password again a second
    // later would teach people that the prompt means nothing.
    $this->withToken($this->loginAs('rina@acme.test'))
        ->patchJson('/api/v1/organization/settings/mfa-policy', ['require_mfa' => false])
        ->assertOk();
});

it('asks again once the window has passed', function (): void {
    $token = $this->loginAs('rina@acme.test');
    staleSession($token);

    $this->withToken($token)
        ->patchJson('/api/v1/organization/settings/mfa-policy', ['require_mfa' => true])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.reauthentication_required')
        ->assertJsonPath('error.details.window_minutes', RecentAuthentication::WINDOW_MINUTES);

    // Refused means nothing happened.
    expect(DB::table('organizations')->where('slug', 'acme')->value('require_mfa'))->toBeFalse();
});

it('keeps the session while it refuses the act', function (): void {
    $token = $this->loginAs('rina@acme.test');
    staleSession($token);

    $this->withToken($token)->patchJson('/api/v1/organization/settings/mfa-policy', ['require_mfa' => true])
        ->assertForbidden();

    // 403, not 401, and this is the whole point: the session is valid and
    // nothing about it is in question. A 401 would tell every client to throw
    // it away and send somebody back to the sign-in screen — the opposite of
    // "confirm one thing and carry on".
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('opens the window again with the password', function (): void {
    $token = $this->loginAs('rina@acme.test');
    staleSession($token);

    $this->withToken($token)
        ->postJson('/api/v1/auth/reauthenticate', ['password' => 'password'])
        ->assertNoContent();

    $this->withToken($token)
        ->patchJson('/api/v1/organization/settings/mfa-policy', ['require_mfa' => true])
        ->assertOk();
});

it('refuses the wrong password and leaves the window shut', function (): void {
    $token = $this->loginAs('rina@acme.test');
    staleSession($token);

    $this->withToken($token)
        ->postJson('/api/v1/auth/reauthenticate', ['password' => 'not-the-password'])
        ->assertUnauthorized();

    $this->withToken($token)
        ->patchJson('/api/v1/organization/settings/mfa-policy', ['require_mfa' => true])
        ->assertForbidden();
});

it('guards erasing a person', function (): void {
    $token = $this->loginAs('rina@acme.test');
    staleSession($token);

    $this->withToken($token)
        ->postJson('/api/v1/people/'.membershipOf('tono@acme.test').'/erase')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.reauthentication_required');

    // The refusal is not a half-erasure: the person is untouched.
    expect(DB::table('memberships')->where('id', membershipOf('tono@acme.test'))->value('erased_at'))
        ->toBeNull();
});

it('guards taking somebody else\'s second factor off', function (): void {
    $token = $this->loginAs('rina@acme.test');
    staleSession($token);

    $this->withToken($token)
        ->deleteJson('/api/v1/people/'.membershipOf('sarah@acme.test').'/mfa')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.reauthentication_required');
});

it('guards the session policy too', function (): void {
    $token = $this->loginAs('rina@acme.test');
    staleSession($token);

    $this->withToken($token)
        ->patchJson('/api/v1/organization/settings/session-policy', ['session_lifetime_days' => 1])
        ->assertForbidden();

    expect(DB::table('organizations')->where('slug', 'acme')->value('session_lifetime_days'))
        ->not->toBe(1);
});

it('leaves ordinary work alone', function (): void {
    $token = $this->loginAs('sarah@acme.test');
    staleSession($token);

    // The safeguard is for a handful of acts. A product that asked for a
    // password before every write would be a product people stop reading
    // prompts in.
    $this->withToken($token)->getJson('/api/v1/work-items')->assertOk();
});

it('confirms for the session that asked, and nobody else\'s', function (): void {
    $laptop = $this->loginAs('rina@acme.test');
    $phone = $this->loginAs('rina@acme.test');

    staleSession($laptop);
    staleSession($phone);

    $this->withToken($laptop)
        ->postJson('/api/v1/auth/reauthenticate', ['password' => 'password'])
        ->assertNoContent();

    // Confirming on the laptop must not open the window on a device somebody
    // else is holding — which is the entire threat this guards against.
    $this->withToken($phone)
        ->patchJson('/api/v1/organization/settings/mfa-policy', ['require_mfa' => true])
        ->assertForbidden();
});

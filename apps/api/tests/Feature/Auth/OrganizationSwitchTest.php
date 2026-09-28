<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Switching organization (docs/06 §1, ADR 0050).
 *
 * docs/06 has promised since Phase 1 that "switching organizations issues a
 * new token", and until now nothing could: login picked the oldest membership
 * and that was that. These tests hold the promise to its wording — a NEW
 * session, bound to the other organization, answering to that organization's
 * rules — and to the one thing a switch must never be: a way for a client to
 * point a session at a tenant it does not belong to.
 *
 * Rina belongs to Acme (org admin) and, since ADR 0050's seed, to Globex as an
 * employee. Ahmad belongs to Acme only.
 */
const SWITCH_ACME = '01900000-0000-7000-8000-0000000000ac';
const SWITCH_GLOBEX = '01900000-0000-7000-8000-0000000000b0';

beforeEach(function (): void {
    $this->rina = $this->loginAs('rina@acme.test');
});

/** Switch and hand back the new session's token. */
function switchTo(string $token, string $organizationId): string
{
    return (string) test()->withToken($token)
        ->postJson('/api/v1/auth/organization', ['organization_id' => $organizationId])
        ->assertOk()
        ->json('data.token');
}

it('lists the organizations a person belongs to, and which one this session is in', function (): void {
    $organizations = collect($this->withToken($this->rina)
        ->getJson('/api/v1/auth/organizations')
        ->assertOk()
        ->json('data'));

    expect($organizations->pluck('id')->all())->toEqualCanonicalizing([SWITCH_ACME, SWITCH_GLOBEX])
        ->and($organizations->firstWhere('id', SWITCH_ACME)['current'])->toBeTrue()
        ->and($organizations->firstWhere('id', SWITCH_GLOBEX)['current'])->toBeFalse();
});

it('issues a new session in the other organization and ends the one that asked', function (): void {
    $globex = switchTo($this->rina, SWITCH_GLOBEX);

    expect($globex)->not->toBe($this->rina);

    $this->withToken($globex)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.organization.id', SWITCH_GLOBEX);

    // The old session is over, with the reason written on it.
    $this->withToken($this->rina)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $oldId = explode('|', $this->rina)[0];

    expect(DB::table('sessions')->where('id', $oldId)->value('revoked_reason'))
        ->toBe('switched_organization');
});

it('acts with the permissions of the membership it switched to, not the one it left', function (): void {
    $globex = switchTo($this->rina, SWITCH_GLOBEX);

    // An org admin in Acme is an employee in Globex, and the session says so.
    $permissions = $this->withToken($globex)->getJson('/api/v1/auth/me')->json('data.permissions');

    expect($permissions)->not->toContain('custom_field.manage')
        ->and($permissions)->not->toContain('webhook.manage');
});

it('cannot see the organization it left', function (): void {
    $globex = switchTo($this->rina, SWITCH_GLOBEX);

    // ENG-142 is Acme's. From a Globex session it does not exist — 404, never
    // 403, because a 403 would confirm it is there (docs/05 §3).
    $this->withToken($globex)->getJson('/api/v1/work-items/ENG-142')->assertNotFound();
});

it('does not open the re-authentication window, because a click proves nothing', function (): void {
    $globex = switchTo($this->rina, SWITCH_GLOBEX);

    $newId = explode('|', $globex)[0];

    expect(DB::table('sessions')->where('id', $newId)->value('reauthenticated_at'))->toBeNull();
});

it('refuses an organization this person does not belong to, exactly as one that does not exist', function (string $organizationId): void {
    $ahmad = $this->loginAs('ahmad@acme.test');   // Acme only

    $this->withToken($ahmad)
        ->postJson('/api/v1/auth/organization', ['organization_id' => $organizationId])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'auth.no_active_membership');

    // And the session that asked is untouched.
    $this->withToken($ahmad)->getJson('/api/v1/auth/me')->assertOk();
})->with([
    'somebody else\'s organization' => SWITCH_GLOBEX,
    'an organization that does not exist' => '01900000-0000-7000-8000-00000000dead',
    'not an id at all' => 'globex',
]);

it('records the switch in both organizations', function (): void {
    switchTo($this->rina, SWITCH_GLOBEX);

    $out = DB::table('audit_logs')->where('event', 'auth.organization_switched_out')->latest('occurred_at')->first();
    $in = DB::table('audit_logs')->where('event', 'auth.organization_switched_in')->latest('occurred_at')->first();

    expect($out?->organization_id)->toBe(SWITCH_ACME)
        ->and($in?->organization_id)->toBe(SWITCH_GLOBEX);
});

it('lets a session confined by one organization\'s second-factor rule leave for another', function (): void {
    // Acme now requires a factor Rina has not enrolled: her session is
    // confined to enrolment. Globex does not require one — leaving must work,
    // or one organization's rule becomes a lockout from every other.
    DB::table('organizations')->where('id', SWITCH_ACME)->update(['require_mfa' => true]);

    $confined = $this->loginAs('rina@acme.test');

    $this->withToken($confined)->getJson('/api/v1/work-items')->assertStatus(403);

    $globex = switchTo($confined, SWITCH_GLOBEX);

    $this->withToken($globex)->getJson('/api/v1/work-items')->assertOk();
});

it('is not something an API token can do — a token stays in the organization it was made in', function (): void {
    $token = (string) $this->withToken($this->rina)
        ->postJson('/api/v1/me/api-tokens', ['name' => 'Hopper', 'access' => 'read_write', 'expires_in_days' => 30])
        ->assertStatus(201)
        ->json('data.token');

    $this->withToken($token)
        ->postJson('/api/v1/auth/organization', ['organization_id' => SWITCH_GLOBEX])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'auth.interactive_session_required');
});

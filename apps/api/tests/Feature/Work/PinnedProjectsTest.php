<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The projects somebody keeps in their own sidebar (docs/08 §1, ADR 0044).
 *
 * docs/08 §1 has drawn a pinned PROJECTS section since Phase 1 — *"Users pin
 * the 3–7 they actually work in"* — and only TEAMS was ever built.
 *
 * The tests that matter are the two about what a pin is NOT: it is not a
 * grant, and it is not a copy of what you can see.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');
    $this->outsider = $this->loginAs('tono@acme.test');
});

it('pins a project and hands it back in the pinned list', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/projects/ENG/pin', ['pinned' => true])
        ->assertNoContent();

    $keys = collect($this->withToken($this->admin)->getJson('/api/v1/me/projects')->json('data'))
        ->pluck('key');

    expect($keys)->toContain('ENG');
});

it('is idempotent in both directions', function (): void {
    // Pinning twice is not an error anybody should be shown: they wanted it
    // pinned, and it is. The unique index is what decides, not a check first.
    $this->withToken($this->admin)->postJson('/api/v1/projects/ENG/pin', ['pinned' => true])->assertNoContent();
    $this->withToken($this->admin)->postJson('/api/v1/projects/ENG/pin', ['pinned' => true])->assertNoContent();

    expect(DB::table('pinned_projects')->count())->toBe(1);

    $this->withToken($this->admin)->postJson('/api/v1/projects/ENG/pin', ['pinned' => false])->assertNoContent();
    $this->withToken($this->admin)->postJson('/api/v1/projects/ENG/pin', ['pinned' => false])->assertNoContent();

    expect(DB::table('pinned_projects')->count())->toBe(0);
});

it('is one person\'s list, not the organization\'s', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/projects/ENG/pin', ['pinned' => true])
        ->assertNoContent();

    // Sarah can see ENG — she is on it — and has pinned nothing.
    $hers = $this->withToken($this->loginAs('sarah@acme.test'))
        ->getJson('/api/v1/me/projects')
        ->assertOk()
        ->json('data');

    expect($hers)->toBe([]);
});

it('is not a grant: a pinned project you lose access to leaves the list', function (): void {
    $tono = DB::table('memberships')
        ->join('users', 'users.id', '=', 'memberships.user_id')
        ->where('users.email', 'tono@acme.test')
        ->value('memberships.id');

    $this->withToken($this->admin)
        ->postJson('/api/v1/projects/FIN/members', ['membership_id' => $tono, 'role' => 'viewer'])
        ->assertStatus(201);

    $this->withToken($this->outsider)
        ->postJson('/api/v1/projects/FIN/pin', ['pinned' => true])
        ->assertNoContent();

    expect(collect($this->withToken($this->outsider)->getJson('/api/v1/me/projects')->json('data'))->pluck('key'))
        ->toContain('FIN');

    $member = collect($this->withToken($this->admin)->getJson('/api/v1/projects/FIN/members')->json('data'))
        ->firstWhere('membership_id', $tono);

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/projects/FIN/members/{$member['id']}")
        ->assertNoContent();

    // The row is still there; the SIDEBAR is not a link that 404s. A pin that
    // survived a revoked grant would be exactly that.
    expect($this->withToken($this->outsider)->getJson('/api/v1/me/projects')->json('data'))
        ->toBe([]);
});

it('hides an archived project without forgetting the pin', function (): void {
    $this->withToken($this->admin)->postJson('/api/v1/projects/ENG/pin', ['pinned' => true])->assertNoContent();
    $this->withToken($this->admin)->postJson('/api/v1/projects/ENG/archive', ['archived' => true])->assertOk();

    expect($this->withToken($this->admin)->getJson('/api/v1/me/projects')->json('data'))->toBe([]);

    // Archiving is reversible (ADR 0040), so dropping the pin would quietly
    // punish somebody for putting a project away for a month.
    expect(DB::table('pinned_projects')->count())->toBe(1);

    $this->withToken($this->admin)->postJson('/api/v1/projects/ENG/archive', ['archived' => false])->assertOk();

    expect(collect($this->withToken($this->admin)->getJson('/api/v1/me/projects')->json('data'))->pluck('key'))
        ->toContain('ENG');
});

it('refuses to pin a project the person cannot see', function (): void {
    $this->withToken($this->outsider)
        ->postJson('/api/v1/projects/FIN/pin', ['pinned' => true])
        ->assertNotFound();
});

<?php

declare(strict_types=1);

/**
 * Teams (docs/02 §4, docs/06 §2).
 *
 * Every endpoint here calls authorize(), and until Phase 5 there was no
 * TeamPolicy for Gate to call — so all of them answered 403, to everyone. These
 * tests exist first to prove they answer at all, and then to pin the boundary
 * that is easiest to get wrong: leading a team is not, by itself, permission to
 * change it.
 */
const FRONTEND = '01900000-0000-7000-8000-000000000801';
const GLOBEX_OPS = '01900000-0000-7000-8000-000000000901';
const BUDI = '01900000-0000-7000-8000-000000000206';  // on Frontend
const LISA = '01900000-0000-7000-8000-000000000207';  // on no team

beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');     // org admin
    $this->lead = $this->loginAs('sarah@acme.test');     // leads Frontend
    $this->employee = $this->loginAs('budi@acme.test');  // member of Frontend
});

it('lists teams with their member counts', function (): void {
    $teams = collect(
        $this->withToken($this->employee)->getJson('/api/v1/teams')->assertOk()->json('data')
    );

    $frontend = $teams->firstWhere('id', FRONTEND);

    expect($teams)->toHaveCount(4)
        // David left Frontend a year ago; the row stays as history and the
        // count must not.
        ->and($frontend['member_count'])->toBe(2);
});

it('shows a team its members', function (): void {
    $team = $this->withToken($this->employee)->getJson('/api/v1/teams/'.FRONTEND)
        ->assertOk()
        ->json('data');

    expect(collect($team['members'])->pluck('name')->all())
        ->toEqualCanonicalizing(['Sarah Chen', 'Budi Santoso'])
        ->and(collect($team['members'])->firstWhere('name', 'Sarah Chen')['role'])->toBe('lead');
});

it('adds a member', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/teams/'.FRONTEND.'/members', ['membership_id' => LISA])
        ->assertOk();

    $names = collect(
        $this->withToken($this->admin)->getJson('/api/v1/teams/'.FRONTEND)->json('data.members')
    )->pluck('name');

    expect($names)->toContain('Lisa Tan');
});

it('does not treat leading a team as permission to manage it', function (): void {
    // Sarah leads Frontend and is an Employee: `team.manage_members` is not in
    // her role. She is refused, and that is the documented answer rather than a
    // gap — "this person may manage THIS team" is a scoped grant, which
    // PermissionResolver already resolves and the roadmap puts in Phase 7. An
    // `if (lead)` here is exactly the hardcoded role check docs/06 §2 rules out
    // by name, and it would have to be removed the day scoped grants ship.
    $this->withToken($this->lead)
        ->postJson('/api/v1/teams/'.FRONTEND.'/members', ['membership_id' => LISA])
        ->assertForbidden();
});

it('lets an ordinary member see a team but not change it', function (): void {
    $this->withToken($this->employee)->getJson('/api/v1/teams/'.FRONTEND)->assertOk();

    $this->withToken($this->employee)
        ->postJson('/api/v1/teams/'.FRONTEND.'/members', ['membership_id' => LISA])
        ->assertForbidden();
});

it('reports the decision it made, so the client renders the same one', function (): void {
    $asAdmin = $this->withToken($this->admin)->getJson('/api/v1/teams/'.FRONTEND)->json('data');
    $asLead = $this->withToken($this->lead)->getJson('/api/v1/teams/'.FRONTEND)->json('data');

    // The client hides what the server already refused; it never decides
    // (docs/06 §2). So these flags must match what the endpoints actually do —
    // including for the lead, who can see the team and change nothing on it.
    expect($asAdmin['permissions']['manage_members'])->toBeTrue()
        ->and($asAdmin['permissions']['delete'])->toBeTrue()
        ->and($asLead['permissions']['manage_members'])->toBeFalse()
        ->and($asLead['permissions']['delete'])->toBeFalse();
});

it('removes a member without erasing that they were one', function (): void {
    $this->withToken($this->admin)
        ->deleteJson('/api/v1/teams/'.FRONTEND.'/members/'.BUDI)
        ->assertNoContent();

    $members = $this->withToken($this->admin)->getJson('/api/v1/teams/'.FRONTEND)->json('data.members');

    expect(collect($members)->pluck('name'))->not->toContain('Budi Santoso');

    // The row is still there, closed rather than deleted: a team's history is
    // how "who was on this when it shipped" stays answerable (docs/02 §4).
    $this->assertDatabaseHas('team_members', [
        'team_id' => FRONTEND,
        'membership_id' => BUDI,
    ]);
});

it('cannot reach another organization\'s team', function (): void {
    $response = $this->withToken($this->admin)->getJson('/api/v1/teams/'.GLOBEX_OPS);

    $response->assertNotFound();
    expect($response->json('error.code'))->toBe('resource.not_found');
});

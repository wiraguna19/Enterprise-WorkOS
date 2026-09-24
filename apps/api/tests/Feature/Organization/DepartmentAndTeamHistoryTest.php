<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Two halves of one gap (ADR 0045).
 *
 * `TeamService` has recorded `created`, `member_added` and `member_removed`
 * since Phase 5 and nothing could read one — a write path with no read path,
 * the same one a project's history had until ADR 0043, one module over.
 *
 * `DepartmentService` is the other half and the worse one: `create` and `move`
 * were recorded and a RENAME was not, because the controller wrote the model
 * directly. A reader of that history saw a department created, a department
 * moved, and concluded nothing else had happened. **A partial record looks
 * complete**, which is why nobody would have reported it.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');
});

it('says what happened to a team, and who did it', function (): void {
    $team = DB::table('teams')->where('key', 'QA')->first();
    $membership = DB::table('memberships')
        ->join('users', 'users.id', '=', 'memberships.user_id')
        ->where('users.email', 'tono@acme.test')
        ->value('memberships.id');

    $this->withToken($this->admin)
        ->postJson("/api/v1/teams/{$team->id}/members", ['membership_id' => $membership])
        ->assertOk();

    $verbs = collect($this->withToken($this->admin)
        ->getJson("/api/v1/teams/{$team->id}/activity")
        ->assertOk()
        ->json('data'))
        ->flatMap(fn (array $group) => collect($group['entries'])->pluck('verb'))
        ->all();

    expect($verbs)->toContain('member_added');
});

it('records a department rename, which it did not before', function (): void {
    $department = DB::table('departments')->where('code', 'MKT')->first();

    $this->withToken($this->admin)
        ->patchJson("/api/v1/departments/{$department->id}", ['name' => 'Growth'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Growth');

    $entry = DB::table('activity_logs')
        ->where('subject_type', 'department')
        ->where('subject_id', $department->id)
        ->where('verb', 'updated')
        ->orderByDesc('occurred_at')
        ->first();

    expect($entry)->not->toBeNull();

    /** @var array<string, array{from: mixed, to: mixed}> $changes */
    $changes = json_decode((string) $entry->changes, true);

    // The OLD value as well as the new one. A history that says only what
    // something became cannot answer "what was it called before".
    expect($changes)->toHaveKey('name')
        ->and($changes['name']['from'])->toBe('Marketing')
        ->and($changes['name']['to'])->toBe('Growth');
});

it('writes nothing when a rename changes nothing', function (): void {
    $department = DB::table('departments')->where('code', 'FIN')->first();

    $before = DB::table('activity_logs')
        ->where('subject_type', 'department')
        ->where('subject_id', $department->id)
        ->count();

    $this->withToken($this->admin)
        ->patchJson("/api/v1/departments/{$department->id}", ['name' => $department->name])
        ->assertOk();

    // A save that logs an unchanged field turns the history into noise nobody
    // reads — the same rule every other update in this codebase follows.
    expect(DB::table('activity_logs')
        ->where('subject_type', 'department')
        ->where('subject_id', $department->id)
        ->count())->toBe($before);
});

it('cannot be asked about another organization\'s team', function (): void {
    // The first draft of this test asserted 403 for a contractor, and the seed
    // contradicts it: EVERY role here holds `team.view`, viewer included. A
    // test whose premise the fixture denies is a test that proves whatever the
    // fixture happens to say — so this asks the question that is actually
    // dangerous instead.
    $globexTeam = DB::table('teams')
        ->where('organization_id', '01900000-0000-7000-8000-0000000000b0')
        ->value('id');

    $this->withToken($this->admin)
        ->getJson("/api/v1/teams/{$globexTeam}/activity")
        ->assertNotFound();
});

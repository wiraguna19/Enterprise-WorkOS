<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Who can see and work on a project (ADR 0041).
 *
 * `project_members` has decided project visibility since Phase 2 — `visibleTo`
 * reads it on every list request — and had **no write path at all**. The
 * creator was inserted as the owner and nobody could ever be added, so a
 * project created as PRIVATE was visible to exactly one person for ever.
 *
 * The create form has offered that setting since Phase 2. It was a setting the
 * product could not complete.
 *
 * The test that matters most is the last one: a private project becoming
 * visible to somebody who was added to it. Everything else is a refusal.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');
    $this->outsider = $this->loginAs('tono@acme.test');
});

it('lists the people and teams with access', function (): void {
    $entries = $this->withToken($this->admin)
        ->getJson('/api/v1/projects/ENG/members')
        ->assertOk()
        ->json('data');

    expect($entries)->not->toBeEmpty()
        // Owners first: a list ordered by insertion makes "who runs this" a
        // question you answer by reading every row.
        ->and($entries[0]['role'])->toBe('owner')
        ->and($entries[0]['subject'])->toBe('person');
});

it('adds a person, and refuses to add them twice', function (): void {
    $tono = DB::table('memberships')
        ->join('users', 'users.id', '=', 'memberships.user_id')
        ->where('users.email', 'tono@acme.test')
        ->value('memberships.id');

    $this->withToken($this->admin)
        ->postJson('/api/v1/projects/ENG/members', [
            'membership_id' => $tono,
            'role' => 'member',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.subject', 'person');

    $this->withToken($this->admin)
        ->postJson('/api/v1/projects/ENG/members', ['membership_id' => $tono])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'project.already_a_member');
});

it('adds a whole team, which is not a copy of its roster', function (): void {
    $qa = DB::table('teams')->where('key', 'QA')->value('id');

    $this->withToken($this->admin)
        ->postJson('/api/v1/projects/ENG/members', ['team_id' => $qa, 'role' => 'member'])
        ->assertStatus(201)
        ->assertJsonPath('data.subject', 'team');

    $this->withToken($this->admin)
        ->postJson('/api/v1/projects/ENG/members', ['team_id' => $qa])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'project.team_already_added');
});

it('refuses a row that names both a person and a team, or neither', function (): void {
    $tono = DB::table('memberships')
        ->join('users', 'users.id', '=', 'memberships.user_id')
        ->where('users.email', 'tono@acme.test')
        ->value('memberships.id');
    $qa = DB::table('teams')->where('key', 'QA')->value('id');

    // The database says the same thing with a CHECK. This says it in a
    // sentence, which a constraint violation would not.
    $this->withToken($this->admin)
        ->postJson('/api/v1/projects/ENG/members', ['membership_id' => $tono, 'team_id' => $qa])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'project.member_subject');

    $this->withToken($this->admin)
        ->postJson('/api/v1/projects/ENG/members', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'project.member_subject');
});

it('keeps at least one owner', function (): void {
    $owner = collect($this->withToken($this->admin)->getJson('/api/v1/projects/ENG/members')->json('data'))
        ->firstWhere('role', 'owner');

    // A project with no owner is editable only by an administrator, and a
    // private one is visible to nobody — the policy reads the owner row.
    $this->withToken($this->admin)
        ->deleteJson("/api/v1/projects/ENG/members/{$owner['id']}")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'project.last_owner');

    // Demotion is the same hole by another route, and is refused the same way.
    $this->withToken($this->admin)
        ->patchJson("/api/v1/projects/ENG/members/{$owner['id']}", ['role' => 'member'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'project.last_owner');
});

it('removes access without deleting the record of it', function (): void {
    $member = collect($this->withToken($this->admin)->getJson('/api/v1/projects/ENG/members')->json('data'))
        ->firstWhere('role', 'member');

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/projects/ENG/members/{$member['id']}")
        ->assertNoContent();

    $row = DB::table('project_members')->where('id', $member['id'])->first();

    // Still there, marked. Who had access to a project and when is exactly the
    // question an audit asks later, and a deleted row answers it with silence.
    expect($row)->not->toBeNull()
        ->and($row->removed_at)->not->toBeNull();
});

it('makes a private project visible to somebody who is added to it', function (): void {
    // The whole point. FIN is the seeded private project; Tono is not on it.
    $this->withToken($this->outsider)
        ->getJson('/api/v1/projects/FIN')
        ->assertNotFound();

    $tono = DB::table('memberships')
        ->join('users', 'users.id', '=', 'memberships.user_id')
        ->where('users.email', 'tono@acme.test')
        ->value('memberships.id');

    $this->withToken($this->admin)
        ->postJson('/api/v1/projects/FIN/members', ['membership_id' => $tono, 'role' => 'viewer'])
        ->assertStatus(201);

    $this->withToken($this->outsider)
        ->getJson('/api/v1/projects/FIN')
        ->assertOk();
});

it('refuses somebody who may read the project but not manage its access', function (): void {
    $this->withToken($this->loginAs('sarah@acme.test'))
        // The id is never read: `authorize()` runs before `validate()`, so the
        // refusal is about who is asking, not about what they sent.
        ->postJson('/api/v1/projects/ENG/members', [
            'membership_id' => '01900000-0000-7000-8000-000000000208',
        ])
        ->assertForbidden();
});

/**
 * Every project write records an activity entry, and for two commits nothing
 * could read one (ADR 0043).
 *
 * A write path with no read path — this project's most repeated defect, and
 * here self-inflicted: the slice that added the writes did not add the reader.
 * It matters most for access, because `project_members` keeps removed rows
 * precisely so "who could see this and when" stays answerable, and the answer
 * was stored and unreachable.
 */
it('says who gained and lost access, and when', function (): void {
    $tono = DB::table('memberships')
        ->join('users', 'users.id', '=', 'memberships.user_id')
        ->where('users.email', 'tono@acme.test')
        ->value('memberships.id');

    $added = $this->withToken($this->admin)
        ->postJson('/api/v1/projects/ENG/members', ['membership_id' => $tono, 'role' => 'viewer'])
        ->assertStatus(201)
        ->json('data.id');

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/projects/ENG/members/{$added}")
        ->assertNoContent();

    $verbs = collect($this->withToken($this->admin)
        ->getJson('/api/v1/projects/ENG/activity')
        ->assertOk()
        ->json('data'))
        ->flatMap(fn (array $group) => collect($group['entries'])->pluck('verb'))
        ->all();

    expect($verbs)->toContain('member_added')
        ->toContain('member_removed');
});

it('refuses the history of a project the reader cannot see', function (): void {
    // 404, like every other refusal about a project somebody is not on: whether
    // it exists is not the answer to disclose (docs/05 §3).
    $this->withToken($this->outsider)
        ->getJson('/api/v1/projects/FIN/activity')
        ->assertNotFound();
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * A project's milestones can be made, changed and removed (ADR 0056).
 *
 * The table has been read since Phase 2 — project health, the calendar — and
 * nothing could write it. These hold the write path, the rule that status and
 * `completed_at` move together, who may do it, and the one delete that the
 * schema itself would have broken.
 */
beforeEach(function (): void {
    $this->ahmad = $this->loginAs('ahmad@acme.test');
});

/** A project of Ahmad's own, so no seeded milestone is in the way. */
function milestoneProject(): string
{
    // Sequential, not random: `(organization_id, key)` is UNIQUE, and a key
    // drawn from a small range is only usually unique (see ProjectHealthTest).
    static $n = 0;
    $n++;

    $key = "MS{$n}";

    test()->withToken(test()->ahmad)
        ->postJson('/api/v1/projects', ['key' => $key, 'name' => "Milestone fixture {$n}"])
        ->assertCreated();

    return $key;
}

it('creates a milestone and lists it with the work it groups', function (): void {
    $key = milestoneProject();

    $created = $this->withToken($this->ahmad)->postJson("/api/v1/projects/{$key}/milestones", [
        'name' => 'Beta',
        'due_date' => '2030-04-01',
    ])->assertCreated()->json('data');

    expect($created['status'])->toBe('open')->and($created['due_date'])->toBe('2030-04-01');

    $projectId = $this->withToken($this->ahmad)->getJson("/api/v1/projects/{$key}")->json('data.id');

    $this->withToken($this->ahmad)->postJson('/api/v1/work-items', [
        'title' => 'Grouped work',
        'type' => 'task',
        'project_id' => $projectId,
        'milestone_id' => $created['id'],
    ])->assertCreated();

    $listed = $this->withToken($this->ahmad)->getJson("/api/v1/projects/{$key}/milestones")
        ->assertOk()
        ->json('data');

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['name'])->toBe('Beta')
        ->and($listed[0]['work_count'])->toBe(1)
        ->and($listed[0]['open_work_count'])->toBe(1);
});

it('lists undated milestones after the dated ones', function (): void {
    $key = milestoneProject();

    foreach ([['Someday', null], ['Late', '2030-09-01'], ['Early', '2030-01-01']] as [$name, $due]) {
        $this->withToken($this->ahmad)
            ->postJson("/api/v1/projects/{$key}/milestones", ['name' => $name, 'due_date' => $due])
            ->assertCreated();
    }

    $names = array_column(
        $this->withToken($this->ahmad)->getJson("/api/v1/projects/{$key}/milestones")->json('data'),
        'name',
    );

    expect($names)->toBe(['Early', 'Late', 'Someday']);
});

it('moves completed_at with the status, both ways', function (): void {
    $key = milestoneProject();
    $id = $this->withToken($this->ahmad)
        ->postJson("/api/v1/projects/{$key}/milestones", ['name' => 'Launch'])
        ->json('data.id');

    $done = $this->withToken($this->ahmad)
        ->patchJson("/api/v1/projects/{$key}/milestones/{$id}", ['status' => 'completed'])
        ->assertOk()
        ->json('data');

    expect($done['completed_at'])->not->toBeNull();

    // "We thought we had shipped it." Reopening clears the time, or the CHECK
    // that couples them refuses the row.
    $reopened = $this->withToken($this->ahmad)
        ->patchJson("/api/v1/projects/{$key}/milestones/{$id}", ['status' => 'at_risk'])
        ->assertOk()
        ->json('data');

    expect($reopened['completed_at'])->toBeNull()->and($reopened['status'])->toBe('at_risk');
});

it('records each change in the project\'s own history', function (): void {
    $key = milestoneProject();
    $id = $this->withToken($this->ahmad)
        ->postJson("/api/v1/projects/{$key}/milestones", ['name' => 'Launch', 'due_date' => '2030-05-01'])
        ->json('data.id');

    $this->withToken($this->ahmad)
        ->patchJson("/api/v1/projects/{$key}/milestones/{$id}", ['due_date' => '2030-06-01'])
        ->assertOk();

    // The feed groups entries by correlation; the verbs are one level down.
    $verbs = collect($this->withToken($this->ahmad)->getJson("/api/v1/projects/{$key}/activity")->json('data'))
        ->flatMap(fn (array $group): array => array_column($group['entries'], 'verb'))
        ->all();

    expect($verbs)->toContain('milestone_added')->toContain('milestone_updated');
});

it('removes a milestone and keeps its work, ungrouped', function (): void {
    // The delete the schema would have broken: the foreign key from
    // work_items is composite, and SET NULL without a column list nulls
    // organization_id too.
    $key = milestoneProject();
    $projectId = $this->withToken($this->ahmad)->getJson("/api/v1/projects/{$key}")->json('data.id');
    $id = $this->withToken($this->ahmad)
        ->postJson("/api/v1/projects/{$key}/milestones", ['name' => 'Cut'])
        ->json('data.id');

    $reference = $this->withToken($this->ahmad)->postJson('/api/v1/work-items', [
        'title' => 'Survives the milestone',
        'type' => 'task',
        'project_id' => $projectId,
        'milestone_id' => $id,
    ])->assertCreated()->json('data.reference');

    $this->withToken($this->ahmad)
        ->deleteJson("/api/v1/projects/{$key}/milestones/{$id}")
        ->assertNoContent();

    $row = DB::table('work_items')->where('reference', $reference)->first(['milestone_id', 'organization_id']);

    expect($row)->not->toBeNull()
        ->and($row->milestone_id)->toBeNull()
        ->and($row->organization_id)->not->toBeNull()
        ->and(DB::table('milestones')->where('id', $id)->exists())->toBeFalse();
});

it('is what project health judges, once somebody makes one', function (): void {
    $key = milestoneProject();

    $before = $this->withToken($this->ahmad)->getJson("/api/v1/insights/projects/{$key}/health")
        ->json('data.signals.milestones.status');

    $this->withToken($this->ahmad)->postJson("/api/v1/projects/{$key}/milestones", [
        'name' => 'Already late',
        'due_date' => now()->subDays(3)->toDateString(),
    ])->assertCreated();

    $after = $this->withToken($this->ahmad)->getJson("/api/v1/insights/projects/{$key}/health")
        ->json('data.signals.milestones.status');

    expect($before)->toBe('unknown')->and($after)->toBe('at_risk');
});

it('refuses an employee, and says nothing of a project they cannot see', function (): void {
    $key = milestoneProject();

    // On the project, so she can SEE it — otherwise the answer is a 404, and
    // the test would prove visibility instead of the policy.
    $this->withToken($this->ahmad)
        ->postJson("/api/v1/projects/{$key}/members", ['membership_id' => '01900000-0000-7000-8000-000000000203', 'role' => 'member'])
        ->assertCreated();

    $this->flushHeaders();
    $sarah = $this->loginAs('sarah@acme.test');

    $this->withToken($sarah)->getJson("/api/v1/projects/{$key}/milestones")->assertOk();

    $this->withToken($sarah)
        ->postJson("/api/v1/projects/{$key}/milestones", ['name' => 'Not hers to set'])
        ->assertForbidden();

    // FIN is private and Sarah is not on it: 404, as for the project itself.
    $this->withToken($sarah)->getJson('/api/v1/projects/FIN/milestones')->assertNotFound();
});

it('answers 404 for a milestone of another project, and for an id that is not one', function (): void {
    $mine = milestoneProject();
    $other = milestoneProject();

    $id = $this->withToken($this->ahmad)
        ->postJson("/api/v1/projects/{$other}/milestones", ['name' => 'Elsewhere'])
        ->json('data.id');

    $this->withToken($this->ahmad)
        ->patchJson("/api/v1/projects/{$mine}/milestones/{$id}", ['name' => 'Moved'])
        ->assertNotFound();

    $this->withToken($this->ahmad)
        ->patchJson("/api/v1/projects/{$mine}/milestones/banana", ['name' => 'Typo'])
        ->assertNotFound();
});

// ── Work in a milestone ─────────────────────────────────────────────────────

it('puts work into a milestone of its own project, and takes it out again', function (): void {
    $key = milestoneProject();
    $projectId = $this->withToken($this->ahmad)->getJson("/api/v1/projects/{$key}")->json('data.id');
    $milestone = $this->withToken($this->ahmad)
        ->postJson("/api/v1/projects/{$key}/milestones", ['name' => 'Into it'])
        ->json('data.id');

    $reference = $this->withToken($this->ahmad)->postJson('/api/v1/work-items', [
        'title' => 'Not grouped yet',
        'type' => 'task',
        'project_id' => $projectId,
    ])->json('data.reference');

    $this->withToken($this->ahmad)
        ->patchJson("/api/v1/work-items/{$reference}", ['milestone_id' => $milestone])
        ->assertOk()
        ->assertJsonPath('data.milestone_id', $milestone);

    $this->withToken($this->ahmad)
        ->patchJson("/api/v1/work-items/{$reference}", ['milestone_id' => null])
        ->assertOk()
        ->assertJsonPath('data.milestone_id', null);
});

it('refuses a milestone of another project, by name, on create and on edit', function (): void {
    $here = milestoneProject();
    $there = milestoneProject();
    $hereId = $this->withToken($this->ahmad)->getJson("/api/v1/projects/{$here}")->json('data.id');
    $elsewhere = $this->withToken($this->ahmad)
        ->postJson("/api/v1/projects/{$there}/milestones", ['name' => 'Not here'])
        ->json('data.id');

    $this->withToken($this->ahmad)->postJson('/api/v1/work-items', [
        'title' => 'Crossed wires',
        'type' => 'task',
        'project_id' => $hereId,
        'milestone_id' => $elsewhere,
    ])->assertUnprocessable()->assertJsonPath('error.code', 'work_item.milestone_not_in_project');

    $reference = $this->withToken($this->ahmad)->postJson('/api/v1/work-items', [
        'title' => 'Straight wires',
        'type' => 'task',
        'project_id' => $hereId,
    ])->json('data.reference');

    $this->withToken($this->ahmad)
        ->patchJson("/api/v1/work-items/{$reference}", ['milestone_id' => $elsewhere])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'work_item.milestone_not_in_project');
});

it('answers an id that names no milestone with a sentence, not a 500', function (): void {
    // Before this, an edit checked only that the id was a uuid, and the
    // foreign key refused it at the database.
    $key = milestoneProject();
    $projectId = $this->withToken($this->ahmad)->getJson("/api/v1/projects/{$key}")->json('data.id');
    $reference = $this->withToken($this->ahmad)->postJson('/api/v1/work-items', [
        'title' => 'Pointed at nothing',
        'type' => 'task',
        'project_id' => $projectId,
    ])->json('data.reference');

    $this->withToken($this->ahmad)
        ->patchJson("/api/v1/work-items/{$reference}", ['milestone_id' => '01900000-0000-7000-8000-00000000dead'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'work_item.milestone_not_in_project');
});

it('refuses a milestone for work that has no project', function (): void {
    $key = milestoneProject();
    $milestone = $this->withToken($this->ahmad)
        ->postJson("/api/v1/projects/{$key}/milestones", ['name' => 'Orphaned'])
        ->json('data.id');

    $this->withToken($this->ahmad)->postJson('/api/v1/work-items', [
        'title' => 'No project',
        'type' => 'task',
        'milestone_id' => $milestone,
    ])->assertUnprocessable()->assertJsonPath('error.code', 'work_item.milestone_not_in_project');
});

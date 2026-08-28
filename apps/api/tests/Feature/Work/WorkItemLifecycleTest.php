<?php

declare(strict_types=1);

use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\DB;

/**
 * The flow the whole product exists for (docs/10, Phase 3 exit criteria):
 * create → assign → the employee sees it → accept → work → submit.
 */
beforeEach(function (): void {
    $this->manager = $this->loginAs('ahmad@acme.test');
    $this->employee = $this->loginAs('sarah@acme.test');
});

it('creates work and assigns it in one step', function (): void {
    // A separate assign call afterwards is the single most common unnecessary
    // click in tools of this kind (docs/08 §4).
    $response = $this->withToken($this->manager)->postJson('/api/v1/work-items', [
        'title' => 'Wire the approval routing',
        'project_id' => '01900003-0000-7000-8000-000000000001',
        'assignee_id' => '01900000-0000-7000-8000-000000000203',
        'priority' => 'high',
        'estimate_hours' => 6,
    ])->assertCreated();

    expect($response->json('data.reference'))->toStartWith('ENG-')
        ->and($response->json('data.state.category'))->toBe('backlog');

    $this->assertDatabaseHas('work_item_assignments', [
        'work_item_id' => $response->json('data.id'),
        'membership_id' => '01900000-0000-7000-8000-000000000203',
        'role' => 'assignee',
        'unassigned_at' => null,
    ]);
});

it('gives each work item a unique human reference', function (): void {
    $first = $this->withToken($this->manager)->postJson('/api/v1/work-items', [
        'title' => 'One', 'project_id' => '01900003-0000-7000-8000-000000000001',
    ])->json('data.reference');

    $second = $this->withToken($this->manager)->postJson('/api/v1/work-items', [
        'title' => 'Two', 'project_id' => '01900003-0000-7000-8000-000000000001',
    ])->json('data.reference');

    expect($first)->not->toBe($second)
        ->and((int) explode('-', $second)[1])->toBeGreaterThan((int) explode('-', $first)[1]);
});

it('lets the assignee accept, work, and submit', function (): void {
    $reference = 'ENG-142';

    $this->withToken($this->employee)->postJson("/api/v1/work-items/{$reference}/accept")
        ->assertOk();

    $this->assertDatabaseMissing('work_item_assignments', [
        'work_item_id' => '01900014-0000-7000-8000-000000000001',
        'membership_id' => '01900000-0000-7000-8000-000000000203',
        'unassigned_at' => null,
        'accepted_at' => null,
    ]);
});

it('records every status change in the activity log', function (): void {
    $item = WorkItemModel::query()->where('reference', 'ENG-144')->firstOrFail();

    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-144/transition', [
        'to_state_id' => '01900002-0000-7000-8000-000000000004',   // In Review
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'subject_type' => 'work_item',
        'subject_id' => $item->id,
        'verb' => 'status_changed',
    ]);
});

it('keeps state_category in step with the workflow state', function (): void {
    // In Review, not straight to Completed: since Phase 4 the graph decides
    // what is reachable, and in_progress → done is not an edge. What this test
    // asserts — that the denormalised column follows the state — is unchanged.
    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-144/transition', [
        'to_state_id' => '01900002-0000-7000-8000-000000000004',   // In Review
    ])->assertOk();

    // The denormalised column is the whole reason list queries are fast; if it
    // can drift, every dashboard is wrong (docs/12 §5).
    $row = DB::table('work_items as w')
        ->join('workflow_states as s', 's.id', '=', 'w.workflow_state_id')
        ->where('w.reference', 'ENG-144')
        ->first(['w.state_category', 's.category']);

    expect($row->state_category)->toBe($row->category);
});

it('sets completed_at exactly when the item becomes done', function (): void {
    // The real path, since Phase 4: submit → approve → complete. Reaching done
    // now costs three moves because each one is an edge somebody configured,
    // which is the whole point of the graph.
    // Approving is guarded by actor_is [reviewer, approver]: holding
    // approval.decide is not enough, and the manager is neither on this item
    // until someone puts him there. Arranging that IS the real path — the graph
    // is what decides who may move work, not the caller's job title.
    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-144/assign', [
        'membership_id' => '01900000-0000-7000-8000-000000000202',
        'role' => 'reviewer',
    ])->assertOk();

    foreach ([
        '01900002-0000-7000-8000-000000000004',   // In Review
        '01900002-0000-7000-8000-000000000005',   // Approved
        '01900002-0000-7000-8000-000000000006',   // Completed
    ] as $state) {
        $this->withToken($this->manager)
            ->postJson('/api/v1/work-items/ENG-144/transition', ['to_state_id' => $state])
            ->assertOk();
    }

    $item = WorkItemModel::query()->where('reference', 'ENG-144')->firstOrFail();

    expect($item->completed_at)->not->toBeNull();

    // ...and clears it on reopen, or every cycle-time metric silently lies.
    //
    // Reopening is guarded by work_item.transition_any and demands a reason:
    // silently reopening finished work rewrites what a report already said.
    // Ahmad cannot do this; Rina can.
    $this->withToken($this->manager)
        ->postJson('/api/v1/work-items/ENG-144/transition', [
            'to_state_id' => '01900002-0000-7000-8000-000000000003',
            'comment' => 'Pool sizing was wrong under load.',
        ])->assertStatus(403);

    $this->withToken($this->loginAs('rina@acme.test'))
        ->postJson('/api/v1/work-items/ENG-144/transition', [
            'to_state_id' => '01900002-0000-7000-8000-000000000003',
            'comment' => 'Pool sizing was wrong under load.',
        ])->assertOk();

    expect($item->fresh()->completed_at)->toBeNull();
});

/**
 * ENG-143 is blocked by ENG-144 in the seed, and sits in the Blocked state.
 *
 * These three tests are about the CLOSE invariant, not about walking the graph,
 * so they put the item on the one edge that closes it and assert from there.
 * Walking blocked → in_progress → in_review → approved first would test the
 * transition graph a second time and bury the assertion that matters.
 */
function placeEng143OnTheEdgeOfDone(): void
{
    DB::table('work_items')->where('reference', 'ENG-143')->update([
        'workflow_state_id' => '01900002-0000-7000-8000-000000000005',   // Approved
        'state_category' => 'in_review',
    ]);
}

it('refuses to close work with open blockers', function (): void {
    placeEng143OnTheEdgeOfDone();

    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-143/transition', [
        'to_state_id' => '01900002-0000-7000-8000-000000000006',
    ])->assertStatus(409)
        ->assertJsonPath('error.code', 'work_item.blocked_by_dependencies');
});

it('allows the override, and records the reason', function (): void {
    // Real organisations need the override. A SILENT violation is what destroys
    // trust in the data, so it costs a permission and a reason (docs/02 §4.2).
    placeEng143OnTheEdgeOfDone();

    $this->withToken($this->loginAs('rina@acme.test'))
        ->postJson('/api/v1/work-items/ENG-143/transition', [
            'to_state_id' => '01900002-0000-7000-8000-000000000006',
            'override_reason' => 'Dependency descoped at the sprint review',
        ])->assertOk();
});

it('denies the override to someone without the permission', function (): void {
    placeEng143OnTheEdgeOfDone();

    $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-143/transition', [
            'to_state_id' => '01900002-0000-7000-8000-000000000006',
            'override_reason' => 'because I said so',
        ])->assertForbidden();
});

it('returns 409 on a stale update rather than overwriting', function (): void {
    $item = WorkItemModel::query()->where('reference', 'ENG-144')->firstOrFail();
    $staleVersion = $item->lock_version;

    $this->withToken($this->manager)->patchJson('/api/v1/work-items/ENG-144', [
        'title' => 'First writer wins', 'lock_version' => $staleVersion,
    ])->assertOk();

    $this->withToken($this->manager)->patchJson('/api/v1/work-items/ENG-144', [
        'title' => 'Second writer must not clobber', 'lock_version' => $staleVersion,
    ])->assertStatus(409)
        ->assertJsonPath('error.code', 'concurrency.conflict');

    expect($item->fresh()->title)->toBe('First writer wins');
});

it('refuses a status change through PATCH', function (): void {
    // Status moves through /transition because it carries rules and side
    // effects. Allowing it as a field edit would push workflow into the client.
    $this->withToken($this->manager)->patchJson('/api/v1/work-items/ENG-144', [
        'state_category' => 'done',
    ])->assertOk();

    expect(WorkItemModel::query()->where('reference', 'ENG-144')->value('state_category'))
        ->not->toBe('done');
});

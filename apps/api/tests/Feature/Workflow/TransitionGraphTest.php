<?php

declare(strict_types=1);

use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The status picker and the API must agree (docs/07 §4).
 *
 * Every test here is the same property from a different angle: the set of moves
 * the interface offers is the set the backend accepts, because both come from
 * one query. A UI that shows a button which then 403s is worse than one that
 * hides it — the user learns not to trust what they see.
 *
 * State ids are written out rather than named by constant: Pest loads every
 * test file into one process, so file-scope constants collide across the suite.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');      // Org Admin
    $this->reviewer = $this->loginAs('ahmad@acme.test');  // Manager, reviewer on ENG-142
    $this->assignee = $this->loginAs('sarah@acme.test');  // Employee, assignee on ENG-142
    $this->bystander = $this->loginAs('budi@acme.test');  // Neither

    $this->state = [
        'todo' => '01900002-0000-7000-8000-000000000002',
        'in_progress' => '01900002-0000-7000-8000-000000000003',
        'in_review' => '01900002-0000-7000-8000-000000000004',
        'approved' => '01900002-0000-7000-8000-000000000005',
        'completed' => '01900002-0000-7000-8000-000000000006',
        'cancelled' => '01900002-0000-7000-8000-000000000008',
    ];
});

it('refuses a move that is not in the graph', function (): void {
    // ENG-142 is In Review. There is no In Review → Completed edge: completing
    // goes through approval. Skipping it is what the graph exists to prevent.
    $this->withToken($this->admin)->postJson('/api/v1/work-items/ENG-142/transition', [
        'to_state_id' => $this->state['completed'],
    ])->assertStatus(409)
        ->assertJsonPath('error.code', 'workflow.illegal_transition');
});

it('offers a blocked transition with a reason rather than hiding it', function (): void {
    // Hiding the button leaves the person asking why the option vanished.
    // Showing it disabled, with the reason, answers the question in place
    // (docs/08 §5).
    $response = $this->withToken($this->assignee)
        ->getJson('/api/v1/work-items/ENG-142/available-transitions')
        ->assertOk();

    $approve = collect($response->json('data.transitions'))
        ->firstWhere('to_state.id', $this->state['approved']);

    expect($approve)->not->toBeNull()
        ->and($approve['available'])->toBeFalse()
        ->and($approve['blocked_reason'])->not->toBeEmpty();
});

it('lets the reviewer approve and refuses the assignee', function (): void {
    $this->withToken($this->assignee)->postJson('/api/v1/work-items/ENG-142/transition', [
        'to_state_id' => $this->state['approved'],
    ])->assertStatus(409)
        ->assertJsonPath('error.code', 'workflow.illegal_transition');

    $this->withToken($this->reviewer)->postJson('/api/v1/work-items/ENG-142/transition', [
        'to_state_id' => $this->state['approved'],
    ])->assertOk();
});

it('guards by role on the item, not by permission alone', function (): void {
    // Rina is an Org Admin and holds approval.decide. She is not a reviewer on
    // THIS item, and the guard names both — holding the permission is not
    // enough. This is the difference between "may approve things" and "was
    // asked to approve this thing".
    $available = collect(
        $this->withToken($this->admin)
            ->getJson('/api/v1/work-items/ENG-142/available-transitions')
            ->assertOk()
            ->json('data.transitions')
    )->firstWhere('to_state.id', $this->state['approved']);

    expect($available['available'])->toBeFalse();
});

it('enforces requires_comment at the API, not just in the form', function (): void {
    // "Request changes" without a reason sends the work round the loop again.
    $this->withToken($this->reviewer)->postJson('/api/v1/work-items/ENG-142/transition', [
        'to_state_id' => $this->state['in_progress'],
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'workflow.comment_required');

    $this->withToken($this->reviewer)->postJson('/api/v1/work-items/ENG-142/transition', [
        'to_state_id' => $this->state['in_progress'],
        'comment' => 'The rollback path drops the index without recreating it.',
    ])->assertOk();
});

it('does not let a wildcard transition bypass a specific guard', function (): void {
    // from_state_id IS NULL means "from anywhere". If the resolver matched it
    // first, a guarded edge could be reached through the wildcard. Cancel is
    // wildcard AND guarded, so an employee must still be refused.
    // 403, not 409: Cancel is guarded by a PERMISSION the employee lacks, and
    // the wildcard does not hand it to her. A 409 here would claim the workflow
    // has no such edge, which is the opposite of what this test is about — the
    // edge exists, is reachable from anywhere, and is still refused.
    //
    // ENG-144, which is In Progress, and not ENG-142, which is In Review: In
    // Review now has its own specific edge to Cancelled for rejections
    // (ADR 0005), so from there the wildcard is not the matching edge at all
    // and this test would silently stop testing what it names.
    $this->withToken($this->assignee)->postJson('/api/v1/work-items/ENG-144/transition', [
        'to_state_id' => $this->state['cancelled'],
        'comment' => 'no longer needed',
    ])->assertStatus(403);
});

it('writes an append-only history row for every move', function (): void {
    $item = WorkItemModel::query()->where('reference', 'ENG-144')->firstOrFail();

    $before = DB::table('work_item_transitions')->where('work_item_id', $item->id)->count();

    // Ahmad, not Rina: "Submit for review" is guarded by actor_is
    // [assignee, creator], and ENG-144 was created by Ahmad. Being an Org Admin
    // is not a way past a guard — the test two above this one asserts exactly
    // that, so driving this one as the admin would contradict it.
    $this->withToken($this->reviewer)->postJson('/api/v1/work-items/ENG-144/transition', [
        'to_state_id' => $this->state['in_review'],
    ])->assertOk();

    $row = DB::table('work_item_transitions')
        ->where('work_item_id', $item->id)
        ->orderByDesc('occurred_at')
        ->first();

    expect(DB::table('work_item_transitions')->where('work_item_id', $item->id)->count())
        ->toBe($before + 1)
        ->and($row->to_state_id)->toBe($this->state['in_review'])
        ->and($row->to_category)->toBe('in_review')
        ->and($row->actor_membership_id)->not->toBeNull()
        // Cause distinguishes a person's click from a rule's side effect.
        // Without it, "who moved this" has no honest answer once automation
        // exists.
        ->and($row->cause)->toBe('user');
});

it('refuses to rewrite transition history', function (): void {
    // The trigger, not the ORM, is what makes this true — the API is not the
    // only thing with a database connection (docs/03 §7).
    $item = WorkItemModel::query()->where('reference', 'ENG-142')->firstOrFail();

    expect(fn () => DB::table('work_item_transitions')
        ->where('work_item_id', $item->id)
        ->update(['to_category' => 'done']))
        ->toThrow(QueryException::class);
});

it('reads the graph rather than assuming one shape', function (): void {
    // REQ-1 runs the request workflow, whose states are New → Triaged →
    // Fulfilled. A picker that hardcoded backlog/todo/in_progress/done would
    // render nonsense here, and the API would accept moves the graph forbids.
    // Read as Maya, who raised REQ-1. Not a detail: REQ-1 belongs to no project,
    // and project-less work reaches visibility only through clauses 2-4 of
    // WorkItemVisibility — being assigned, having created it, or watching it.
    // Even an Org Admin cannot see it, which is worth knowing on its own.
    $targets = collect(
        $this->withToken($this->loginAs('maya@acme.test'))
            ->getJson('/api/v1/work-items/REQ-1/available-transitions')
            ->assertOk()
            ->json('data.transitions')
    )->pluck('to_state.id');

    expect($targets)->not->toContain($this->state['todo'])
        ->and($targets)->not->toContain($this->state['in_review']);
});

it('never offers a transition back to the current state', function (): void {
    // The wildcard "Mark blocked" edge applies from any state, Blocked
    // included — a picker that offers it there is offering a no-op.
    DB::table('work_items')->where('reference', 'ENG-143')->update([
        'workflow_state_id' => '01900002-0000-7000-8000-000000000007',
        'state_category' => 'blocked',
    ]);

    $targets = collect(
        $this->withToken($this->admin)
            ->getJson('/api/v1/work-items/ENG-143/available-transitions')
            ->assertOk()
            ->json('data.transitions')
    )->pluck('to_state.id');

    expect($targets)->not->toContain('01900002-0000-7000-8000-000000000007');
});

it('records the opening state as a transition when work is created', function (): void {
    // Landing in the initial state is a transition and needs a row: without
    // it, "how long did this sit in backlog" has nothing to subtract from.
    $item = $this->withToken($this->admin)->postJson('/api/v1/work-items', [
        'title' => 'History starts at creation',
        'project_id' => '01900003-0000-7000-8000-000000000001',
    ])->assertCreated()->json('data');

    $rows = DB::table('work_item_transitions')
        ->where('work_item_id', $item['id'])
        ->orderBy('occurred_at')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->from_state_id)->toBeNull()
        ->and($rows[0]->to_category)->toBe('backlog')
        // Nobody chose this state; the workflow did.
        ->and($rows[0]->cause)->toBe('system');
});

it('lets the override holder bypass a guard, but not invent an edge', function (): void {
    // The override is a real organisational need. What it must NOT become is a
    // way to reach any state at all — an override that ignored the graph would
    // make the whole graph advisory.
    $this->withToken($this->admin)->postJson('/api/v1/work-items/ENG-142/transition', [
        'to_state_id' => '01900002-0000-7000-8000-000000000001',   // back to Backlog
        'override_reason' => 'Send it all the way back',
    ])->assertStatus(409)
        ->assertJsonPath('error.code', 'workflow.illegal_transition');
});

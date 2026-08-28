<?php

declare(strict_types=1);

use App\Modules\Work\Infrastructure\Eloquent\WorkItemAssignmentModel;

/**
 * The feature that justifies assignment being an entity (docs/02 §6).
 *
 * If any of these pass while rows are being deleted rather than closed, the
 * history is a lie — so several assert on what is STILL there, not only on what
 * changed.
 */
beforeEach(function (): void {
    $this->manager = $this->loginAs('ahmad@acme.test');
});

it('closes the outgoing row instead of deleting it', function (): void {
    $before = WorkItemAssignmentModel::query()
        ->where('work_item_id', '01900014-0000-7000-8000-000000000001')
        ->count();

    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-142/assign', [
        'membership_id' => '01900000-0000-7000-8000-000000000205',   // Maya
        'reason' => 'Sarah is at capacity this week',
    ])->assertOk();

    $after = WorkItemAssignmentModel::query()
        ->where('work_item_id', '01900014-0000-7000-8000-000000000001')
        ->count();

    expect($after)->toBe($before + 1, 'a reassignment must ADD a row, never replace one');

    $this->assertDatabaseHas('work_item_assignments', [
        'work_item_id' => '01900014-0000-7000-8000-000000000001',
        'membership_id' => '01900000-0000-7000-8000-000000000203',   // Sarah
        'unassigned_reason' => 'Sarah is at capacity this week',
    ]);
});

it('keeps exactly one active primary assignee', function (): void {
    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-142/assign', [
        'membership_id' => '01900000-0000-7000-8000-000000000205',
    ])->assertOk();

    $active = WorkItemAssignmentModel::query()
        ->where('work_item_id', '01900014-0000-7000-8000-000000000001')
        ->where('role', 'assignee')
        ->whereNull('unassigned_at')
        ->count();

    expect($active)->toBe(1);
});

it('returns the whole narrative in order', function (): void {
    $history = $this->withToken($this->manager)
        ->getJson('/api/v1/work-items/ENG-142/assignments')
        ->assertOk()
        ->json('data');

    $assignees = array_values(array_filter($history, fn ($h) => $h['role'] === 'assignee'));

    // The exact story docs/02 §6 describes: David had it, handed it to Sarah,
    // and the reason survives.
    expect($assignees[0]['person'])->toBe('David Park')
        ->and($assignees[0]['active'])->toBeFalse()
        ->and($assignees[0]['reason'])->toContain('checkout incident')
        ->and($assignees[1]['person'])->toBe('Sarah Chen')
        ->and($assignees[1]['active'])->toBeTrue();
});

it('records a reviewer alongside the assignee, not instead of them', function (): void {
    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-144/assign', [
        'membership_id' => '01900000-0000-7000-8000-000000000205',
        'role' => 'reviewer',
    ])->assertOk();

    $active = WorkItemAssignmentModel::query()
        ->where('work_item_id', '01900014-0000-7000-8000-000000000003')
        ->whereNull('unassigned_at')
        ->pluck('role');

    expect($active)->toContain('assignee')->toContain('reviewer');
});

it('refuses to assign the same person twice in the same role', function (): void {
    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-142/assign', [
        'membership_id' => '01900000-0000-7000-8000-000000000203',   // already the assignee
    ])->assertStatus(409)
        ->assertJsonPath('error.code', 'work_item.already_assigned');
});

it('denies assignment to an employee', function (): void {
    // Employees can do their own work; handing work to someone else is a
    // manager action (see the seeded role grants).
    $this->withToken($this->loginAs('sarah@acme.test'))
        ->postJson('/api/v1/work-items/ENG-144/assign', [
            'membership_id' => '01900000-0000-7000-8000-000000000205',
        ])->assertForbidden();
});

it('leaves work unassigned rather than blocking removal', function (): void {
    $assignment = WorkItemAssignmentModel::query()
        ->where('work_item_id', '01900014-0000-7000-8000-000000000003')
        ->where('role', 'assignee')
        ->whereNull('unassigned_at')
        ->firstOrFail();

    $this->withToken($this->manager)
        ->deleteJson("/api/v1/work-items/ENG-144/assignees/{$assignment->id}")
        ->assertNoContent();

    // Unassigned work is a legitimate state that dashboards must surface, not
    // an impossible one (docs/08 §3).
    expect($assignment->fresh()->unassigned_at)->not->toBeNull();
});

it('surfaces assignments nobody has accepted', function (): void {
    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-144/assign', [
        'membership_id' => '01900000-0000-7000-8000-000000000205',
    ])->assertOk();

    // Asserted at the row first: if the assignment itself is missing, the empty
    // dashboard below is a symptom, and looking for the bug in the query would
    // be looking in the wrong place.
    $this->assertDatabaseHas('work_item_assignments', [
        'work_item_id' => '01900014-0000-7000-8000-000000000003',
        'membership_id' => '01900000-0000-7000-8000-000000000205',
        'role' => 'assignee',
        'accepted_at' => null,
        'unassigned_at' => null,
    ]);

    $needsAttention = $this->withToken($this->loginAs('maya@acme.test'))
        ->getJson('/api/v1/me/work/needs-attention')
        ->assertOk()
        ->json('data.unaccepted');

    $references = collect($needsAttention)->pluck('reference');

    // toContain takes VALUES, not a message: a second argument here is a second
    // thing being looked for, which is how "contains ENG-144" turned into a
    // search for the diagnostic string itself.
    expect($references)->toContain('ENG-144');
});

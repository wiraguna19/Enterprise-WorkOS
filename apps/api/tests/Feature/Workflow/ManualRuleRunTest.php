<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Trying a rule against one work item (ADR 0035).
 *
 * `workflow.run_rule` was the last entry on the bill in
 * `EveryPermissionMeansSomethingTest`, and its note read: "the rule screens show
 * what a rule DID and cannot make it run." What that costs in practice is the
 * thing an administrator does instead — breaking a real work item to find out
 * whether the rule they just wrote works.
 */
const FLAG_UNASSIGNED_URGENT = '01900021-0000-7000-8000-000000000002';
const OPEN_REVIEW_ON_SUBMIT = '01900021-0000-7000-8000-000000000001';

function anUrgentUnassignedItem(): string
{
    $id = (string) DB::table('work_items')->where('reference', 'ENG-45')->value('id');

    DB::table('work_items')->where('id', $id)->update(['priority' => 'urgent']);
    DB::table('work_item_assignments')
        ->where('work_item_id', $id)
        ->where('role', 'assignee')
        ->update(['unassigned_at' => now()]);

    return $id;
}

it('says what a rule would do, and does nothing', function (): void {
    anUrgentUnassignedItem();

    $before = DB::table('workflow_rule_runs')->count();

    $preview = $this->withToken($this->loginAs('rina@acme.test'))
        ->postJson('/api/v1/workflow-rules/'.FLAG_UNASSIGNED_URGENT.'/run', ['reference' => 'ENG-45'])
        ->assertOk()
        ->json('data');

    expect($preview['matched'])->toBeTrue()
        ->and($preview['applied'])->toBeFalse()
        ->and($preview['actions'][0]['type'])->toBe('notify');

    // A preview is not written to the run log: that log records what the system
    // DID, and a preview did nothing.
    expect(DB::table('workflow_rule_runs')->count())->toBe($before);
});

it('says so when the conditions do not match', function (): void {
    // ENG-45 as the seed leaves it: not urgent, and assigned.
    $preview = $this->withToken($this->loginAs('rina@acme.test'))
        ->postJson('/api/v1/workflow-rules/'.FLAG_UNASSIGNED_URGENT.'/run', ['reference' => 'ENG-45'])
        ->assertOk()
        ->json('data');

    expect($preview['matched'])->toBeFalse()
        ->and($preview['actions'])->toBe([]);
});

it('names the facts a manual run cannot know', function (): void {
    // This rule asks whether the item JUST moved into In Review. An item
    // sitting still has no such moment, and answering "did not match" without
    // saying why would send somebody rewriting a rule that was never wrong.
    $preview = $this->withToken($this->loginAs('rina@acme.test'))
        ->postJson('/api/v1/workflow-rules/'.OPEN_REVIEW_ON_SUBMIT.'/run', ['reference' => 'ENG-45'])
        ->assertOk()
        ->json('data');

    expect($preview['unavailable_facts'])->toContain('to_state_key');
});

it('runs the rule for real when asked, and records who asked', function (): void {
    $itemId = anUrgentUnassignedItem();

    $this->withToken($this->loginAs('rina@acme.test'))
        ->postJson('/api/v1/workflow-rules/'.FLAG_UNASSIGNED_URGENT.'/run', [
            'reference' => 'ENG-45',
            'apply' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.applied', true)
        ->assertJsonPath('data.outcome', 'applied');

    $run = DB::table('workflow_rule_runs')
        ->where('rule_id', FLAG_UNASSIGNED_URGENT)
        ->where('subject_id', $itemId)
        ->orderByDesc('occurred_at')
        ->first();

    expect($run->outcome)->toBe('applied')
        // Null is the system's own doing; a hand-run names the person, so the
        // screen that answers "why did this move?" does not say "a rule did it"
        // and hide the button press a second earlier.
        ->and($run->triggered_by_membership_id)->not->toBeNull();

    expect(DB::table('audit_logs')->where('event', 'workflow.rule_run_by_hand')->count())
        ->toBe(1);
});

it('refuses a reference that names nothing here', function (): void {
    $this->withToken($this->loginAs('rina@acme.test'))
        ->postJson('/api/v1/workflow-rules/'.FLAG_UNASSIGNED_URGENT.'/run', ['reference' => 'NOPE-1'])
        ->assertNotFound();
});

it('refuses a reference from another organization', function (): void {
    $theirs = (string) DB::table('work_items')
        ->where('organization_id', '01900000-0000-7000-8000-0000000000b0')
        ->value('reference');

    // 404, not 403: "that item is real but not yours" is a fact nobody outside
    // the organization is owed.
    $this->withToken($this->loginAs('rina@acme.test'))
        ->postJson('/api/v1/workflow-rules/'.FLAG_UNASSIGNED_URGENT.'/run', ['reference' => $theirs])
        ->assertNotFound();
});

it('takes the reference as it is typed', function (): void {
    $this->withToken($this->loginAs('rina@acme.test'))
        ->postJson('/api/v1/workflow-rules/'.FLAG_UNASSIGNED_URGENT.'/run', ['reference' => ' eng-45 '])
        ->assertOk();
});

it('needs the permission that names the act', function (): void {
    // Ahmad manages people and projects; he does not hold workflow.run_rule.
    $this->withToken($this->loginAs('ahmad@acme.test'))
        ->postJson('/api/v1/workflow-rules/'.FLAG_UNASSIGNED_URGENT.'/run', ['reference' => 'ENG-45'])
        ->assertForbidden();
});

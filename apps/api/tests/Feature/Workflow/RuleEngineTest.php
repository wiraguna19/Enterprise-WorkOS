<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Application\Service\RuleEngine;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowRuleModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The three properties that matter about a rule engine are all about failure:
 * it terminates, it is observable, and one broken rule does not stop the rest
 * (docs/02 §7).
 */
beforeEach(function (): void {
    $this->acme = '01900000-0000-7000-8000-0000000000ac';
    $this->workItemId = '01900014-0000-7000-8000-000000000001';   // ENG-142
    $this->engine = app(RuleEngine::class);

    app(TenantContext::class)->setFromSession(
        $this->acme,
        '01900000-0000-7000-8000-000000000202',
        '01900000-0000-7000-8000-000000000002',
    );
});

it('logs the rules that did not match, not only the ones that did', function (): void {
    // "Why didn't my rule fire?" is the first question anyone asks of any
    // automation system, and it is unanswerable if only successes are recorded.
    $this->engine->dispatch(
        trigger: 'work_item.status_changed',
        subjectType: 'work_item',
        subjectId: $this->workItemId,
        facts: ['x' => ['from' => 'todo', 'to' => 'in_progress'], 'priority' => 'low'],
    );

    $outcomes = DB::table('workflow_rule_runs')
        ->where('subject_id', $this->workItemId)
        ->where('occurred_at', '>', now()->subMinute())
        ->pluck('outcome');

    expect($outcomes)->toContain('skipped');
});

it('never evaluates a rule that is switched off', function (): void {
    $disabled = '01900021-0000-7000-8000-000000000005';

    $before = DB::table('workflow_rule_runs')->where('rule_id', $disabled)->count();

    $this->engine->dispatch(
        'work_item.status_changed',
        'work_item',
        $this->workItemId,
        ['x' => ['from' => 'in_review', 'to' => 'done']],
    );

    expect(DB::table('workflow_rule_runs')->where('rule_id', $disabled)->count())->toBe($before);
});

it('stops a circular rule pair instead of looping forever', function (): void {
    // Two rules that trigger each other are the single most common way a
    // workflow engine takes down a queue. The chain must terminate on its own,
    // without a human noticing.
    $causation = (string) new UuidV7;

    $result = $this->engine->dispatch(
        trigger: 'work_item.status_changed',
        subjectType: 'work_item',
        subjectId: $this->workItemId,
        facts: ['x' => ['from' => 'todo', 'to' => 'in_review']],
        causationId: $causation,
        depth: RuleEngine::MAX_DEPTH + 1,
    );

    expect($result)->toBe([]);
});

it('refuses a causation chain deeper than the schema allows', function (): void {
    // Belt and braces: the engine guards at 5, and the column CHECK refuses
    // anything past 10. Two independent ceilings, because the engine is not the
    // only writer.
    expect(fn () => DB::table('work_item_transitions')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => $this->acme,
        'work_item_id' => $this->workItemId,
        'from_state_id' => null,
        'to_state_id' => '01900002-0000-7000-8000-000000000003',
        'from_category' => null,
        'to_category' => 'in_progress',
        'cause' => 'rule',
        'causation_depth' => 99,
        'occurred_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('isolates a failing rule so the others still run', function (): void {
    // A rule naming an action that does not exist is the realistic version of
    // this: someone edits the JSON by hand. It must fail alone.
    $broken = new WorkflowRuleModel;
    $broken->forceFill([
        'id' => (string) new UuidV7,
        'organization_id' => $this->acme,
        'workflow_id' => null,
        'name' => 'Broken rule',
        'trigger' => 'work_item.status_changed',
        // Arrays, not json_encode(): the model casts these columns, so encoding
        // here stores a JSON string INSIDE a JSON column. It reads back as a
        // string, matches nothing, and the rule is skipped — which looks
        // exactly like a rule engine that does not detect broken rules.
        'conditions' => ['all' => []],
        'actions' => [['type' => 'delete_everything', 'with' => []]],
        'is_active' => true,
        'run_order' => -1,   // first, so a global abort would take the rest with it
    ])->save();

    $results = $this->engine->dispatch(
        'work_item.status_changed',
        'work_item',
        $this->workItemId,
        ['x' => ['from' => 'in_progress', 'to' => 'in_review'], 'workflow_id' => '01900001-0000-7000-8000-000000000001'],
    );

    $outcomes = collect($results)->pluck('outcome');

    expect($outcomes)->toContain('failed')
        ->and($outcomes->contains(fn ($o) => $o !== 'failed'))->toBeTrue();
});

it('takes a rule out of service after repeated failures instead of retrying forever', function (): void {
    $rule = new WorkflowRuleModel;
    $id = (string) new UuidV7;
    $rule->forceFill([
        'id' => $id,
        'organization_id' => $this->acme,
        'workflow_id' => null,
        'name' => 'Persistently broken',
        'trigger' => 'work_item.created',
        'conditions' => ['all' => []],
        'actions' => [['type' => 'nonexistent', 'with' => []]],
        'is_active' => true,
        'run_order' => 0,
    ])->save();

    foreach (range(1, 5) as $_) {
        $this->engine->dispatch('work_item.created', 'work_item', $this->workItemId, []);
    }

    $fresh = WorkflowRuleModel::query()->findOrFail($id);

    expect($fresh->is_active)->toBeFalse()
        // A disabled rule with no stated reason is a mystery for whoever finds
        // it next. The reason is the whole value of disabling rather than
        // retrying.
        ->and($fresh->disabled_reason)->not->toBeEmpty();
});

it('clears the failure count when a rule succeeds', function (): void {
    // The threshold is for CONSECUTIVE failures. A transient blip must not
    // accumulate toward disabling a rule that works.
    $rule = WorkflowRuleModel::query()
        ->findOrFail('01900021-0000-7000-8000-000000000002');

    $rule->forceFill(['failure_count' => 3])->save();

    $this->engine->dispatch('work_item.created', 'work_item', $this->workItemId, [
        'priority' => 'urgent',
        'assignee_membership_id' => null,
    ]);

    expect($rule->fresh()->failure_count)->toBe(0);
});

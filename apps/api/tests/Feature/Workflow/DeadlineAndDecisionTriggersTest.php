<?php

declare(strict_types=1);

use App\Modules\Approval\Domain\Event\ApprovalDecided;
use App\Modules\Workflow\Application\Service\RuleVocabulary;
use App\Modules\Workflow\Infrastructure\Job\EvaluateWorkflowRules;
use App\Modules\Workflow\Infrastructure\Listener\DispatchRuleEvaluation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * The rule triggers that were offered and never fired (ADR 0057).
 *
 * `schedule.due_soon`, `schedule.overdue` and `approval.decided` were in the
 * rule builder since Phase 4 with nothing dispatching them: a rule on them
 * saved, showed as active, and never ran. These hold that each is now emitted
 * — once per crossing, for the deadlines — and carries the facts the builder
 * offers for it.
 */
beforeEach(function (): void {
    $this->ahmad = $this->loginAs('ahmad@acme.test');
});

/** A fresh ENG item due at the given moment; returns [id, reference]. */
function itemDueAt(DateTimeInterface $dueAt): array
{
    $project = test()->withToken(test()->ahmad)->getJson('/api/v1/projects/ENG')->json('data.id');

    $data = test()->withToken(test()->ahmad)->postJson('/api/v1/work-items', [
        'title' => 'Deadline subject',
        'type' => 'task',
        'project_id' => $project,
        'due_at' => $dueAt->format(DATE_ATOM),
    ])->assertCreated()->json('data');

    return [(string) $data['id'], (string) $data['reference']];
}

/**
 * The triggers the rule jobs pushed so far were for, for one subject.
 *
 * @return list<string>
 */
function triggersPushedFor(string $subjectId): array
{
    $triggers = [];

    Queue::assertPushed(EvaluateWorkflowRules::class, function (EvaluateWorkflowRules $job) use ($subjectId, &$triggers): bool {
        $subject = (new ReflectionProperty(EvaluateWorkflowRules::class, 'subjectId'))->getValue($job);

        if ($subject === $subjectId) {
            $triggers[] = (new ReflectionProperty(EvaluateWorkflowRules::class, 'trigger'))->getValue($job);
        }

        return true;
    });

    return $triggers;
}

it('announces a deadline that was just missed, once', function (): void {
    [$id] = itemDueAt(now()->subHour());

    Queue::fake();

    $this->artisan('workflow:scan-deadlines')->assertSuccessful();
    $this->artisan('workflow:scan-deadlines')->assertSuccessful();

    expect(triggersPushedFor($id))->toBe(['schedule.overdue']);
});

it('announces a deadline that is coming within the day', function (): void {
    [$id] = itemDueAt(now()->addHours(3));

    Queue::fake();

    $this->artisan('workflow:scan-deadlines')->assertSuccessful();

    expect(triggersPushedFor($id))->toBe(['schedule.due_soon']);
});

it('does not announce what was already late before it could be asked about', function (): void {
    // Three days late: overdue before this scan existed. Announcing it now
    // would be one notification per old late item, all at once.
    [$id] = itemDueAt(now()->subDays(3));

    Queue::fake();

    $this->artisan('workflow:scan-deadlines')->assertSuccessful();

    expect(triggersPushedFor($id))->toBe([]);
});

it('announces a moved deadline again, because it is a new one', function (): void {
    [$id, $reference] = itemDueAt(now()->subHours(2));

    Queue::fake();

    $this->artisan('workflow:scan-deadlines')->assertSuccessful();

    $this->withToken($this->ahmad)
        ->patchJson("/api/v1/work-items/{$reference}", ['due_at' => now()->subHour()->format(DATE_ATOM)])
        ->assertOk();

    $this->artisan('workflow:scan-deadlines')->assertSuccessful();

    expect(triggersPushedFor($id))->toBe(['schedule.overdue', 'schedule.overdue'])
        ->and(DB::table('work_item_deadline_signals')->where('work_item_id', $id)->count())->toBe(2);
});

it('says nothing about finished work', function (): void {
    [$id] = itemDueAt(now()->subHour());

    // With `completed_at`: ck_work_items_completed couples the two, as the
    // product's own transitions do.
    DB::table('work_items')->where('id', $id)->update(['state_category' => 'done', 'completed_at' => now()]);

    Queue::fake();

    $this->artisan('workflow:scan-deadlines')->assertSuccessful();

    expect(triggersPushedFor($id))->toBe([]);
});

it('hands a decision on a work item to the rules, with the facts the builder offers for it', function (): void {
    Queue::fake();

    app(DispatchRuleEvaluation::class)->onApprovalDecided(new ApprovalDecided(
        organizationId: '01900000-0000-7000-8000-0000000000ac',
        approvalId: '01900000-0000-7000-8000-00000000abcd',
        subjectType: 'work_item',
        subjectId: '01900014-0000-7000-8000-000000000001',   // ENG-142
        decision: 'changes_requested',
        resolution: null,
        reviewerMembershipId: '01900000-0000-7000-8000-000000000202',
        requestedByMembershipId: '01900000-0000-7000-8000-000000000203',
    ));

    $facts = [];

    Queue::assertPushed(EvaluateWorkflowRules::class, function (EvaluateWorkflowRules $job) use (&$facts): bool {
        expect((new ReflectionProperty(EvaluateWorkflowRules::class, 'trigger'))->getValue($job))->toBe('approval.decided');
        $facts = (new ReflectionProperty(EvaluateWorkflowRules::class, 'facts'))->getValue($job);

        return true;
    });

    // The same rule RuleVocabularyTest holds for work_item.created: the
    // builder must not offer a fact this trigger never supplies.
    $declared = array_keys(array_filter(
        RuleVocabulary::FIELDS,
        static fn (array $field): bool => in_array('approval.decided', $field['triggers'], strict: true),
    ));

    expect(array_diff($declared, array_keys($facts)))->toBe([])
        ->and($facts['decision'])->toBe('changes_requested')
        // Present and null: `resolution` is set only by a decision that
        // closes the approval, and a key that exists is a fact supplied.
        ->and($facts)->toHaveKey('resolution');
});

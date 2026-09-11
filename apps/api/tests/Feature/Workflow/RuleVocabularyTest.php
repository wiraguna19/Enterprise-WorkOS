<?php

declare(strict_types=1);

use App\Modules\Work\Domain\Event\WorkItemCreated;
use App\Modules\Workflow\Application\Service\ActionExecutor;
use App\Modules\Workflow\Application\Service\RuleVocabulary;
use App\Modules\Workflow\Domain\ConditionEvaluator;
use App\Modules\Workflow\Infrastructure\Job\EvaluateWorkflowRules;
use App\Modules\Workflow\Infrastructure\Listener\DispatchRuleEvaluation;
use Illuminate\Support\Facades\Queue;

/**
 * The vocabulary a builder may offer, held to the engine that implements it.
 *
 * Two lists that must agree eventually will not — this codebase has paid for
 * that four times, and the rule it drew is that the thing which CONSUMES a
 * value should not be the thing that names it. `RuleVocabulary` derives its
 * triggers, operators and actions from the classes that run them, so those
 * three cannot drift.
 *
 * `FIELDS` is the exception and the reason this file exists: the facts are
 * assembled per trigger by listeners, and there is nothing to derive them from.
 * A declaration checked against what a real event produces is the most that can
 * be done — and it is enough, because a field that stops being supplied fails
 * here rather than silently never matching in production.
 */
it('offers no action the executor could not run', function (): void {
    // An action the builder offers and the executor has no handler for throws
    // UnknownAction inside a queued job, hours later, in a log nobody opened.
    $offered = $this->withToken($this->loginAs('rina@acme.test'))
        ->getJson('/api/v1/workflow-vocabulary')
        ->assertOk()
        ->json('data.actions');

    expect($offered)->toBe(ActionExecutor::types());
});

it('offers only facts a real event actually supplies', function (): void {
    Queue::fake();

    app(DispatchRuleEvaluation::class)->onCreated(new WorkItemCreated(
        organizationId: '01900000-0000-7000-8000-0000000000ac',
        workItemId: '01900014-0000-7000-8000-000000000001',   // ENG-142
        type: 'task',
        projectId: null,
        actorMembershipId: '01900000-0000-7000-8000-000000000203',
    ));

    $facts = [];

    Queue::assertPushed(EvaluateWorkflowRules::class, function (EvaluateWorkflowRules $job) use (&$facts): bool {
        // The job keeps its facts private, as it should — nothing in the
        // application reads them from outside. Reflection is the test paying
        // for that rather than the production code loosening for it.
        $property = new ReflectionProperty(EvaluateWorkflowRules::class, 'facts');
        $facts = $property->getValue($job);

        return true;
    });

    $declared = array_keys(array_filter(
        RuleVocabulary::FIELDS,
        static fn (array $field): bool => in_array('work_item.created', $field['triggers'], strict: true),
    ));

    // Subset, not equality: supplying a fact the builder does not offer is a
    // choice (`reference` and `workflow_id` are ids nobody writes a rule
    // against by hand). Offering one nothing supplies is a rule that can never
    // match and will never say why.
    expect(array_diff($declared, array_keys($facts)))->toBe([]);
});

it('offers no comparison the evaluator cannot make', function (): void {
    $offered = $this->withToken($this->loginAs('rina@acme.test'))
        ->getJson('/api/v1/workflow-vocabulary')
        ->assertOk()
        ->json('data.operators');

    // The evaluator treats an unknown operator as false — safe, and invisible.
    // The builder must not be able to compose one.
    expect($offered)->toBe(ConditionEvaluator::OPERATORS);
});

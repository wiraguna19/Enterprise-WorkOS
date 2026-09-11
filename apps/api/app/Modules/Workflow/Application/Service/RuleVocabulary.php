<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service;

use App\Modules\Notification\Application\Service\NotificationDispatcher;
use App\Modules\Workflow\Domain\ConditionEvaluator;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowRuleModel;

/**
 * Everything a rule may legally say, assembled from the code that implements it.
 *
 * A builder has to offer a list of triggers, operators and actions, and the
 * moment that list lives in the interface it is a copy — **two lists that must
 * agree eventually will not.** This codebase has paid for that four times
 * (SUPPORTED_FORMATS beside an injected writer; a client keeping its own format
 * list; a screen keeping its own copy of what a report requires; the New work
 * item form mirroring WorkItemModel::TYPES). So the triggers come from
 * `WorkflowRuleModel`, the operators from `ConditionEvaluator`, and the actions
 * from `ActionExecutor`'s registry: an action the builder offers and the
 * executor cannot run throws inside a queued job, where nobody is watching.
 *
 * FIELDS is the one list here that is genuinely written down twice, because
 * the facts are assembled per trigger by listeners and there is nothing to
 * derive it from. `RuleVocabularyTest` holds it to the facts a real event
 * produces — a declaration checked against reality is a copy that cannot rot
 * quietly, which is the most this can be.
 */
final class RuleVocabulary
{
    /**
     * The facts a condition may name.
     *
     * `values` is present only where the set is closed and small enough to
     * offer as a choice; null means free text, and the builder must not invent
     * a list for it.
     *
     * @var array<string, array{type: string, values: list<string>|null, triggers: list<string>}>
     */
    public const FIELDS = [
        'type' => [
            'type' => 'string',
            'values' => ['task', 'request', 'approval_work', 'incident', 'review', 'campaign', 'operational'],
            'triggers' => ['work_item.status_changed', 'work_item.assigned', 'work_item.created', 'schedule.due_soon', 'schedule.overdue'],
        ],
        'priority' => [
            'type' => 'string',
            'values' => ['low', 'medium', 'high', 'urgent'],
            'triggers' => ['work_item.status_changed', 'work_item.assigned', 'work_item.created', 'schedule.due_soon', 'schedule.overdue'],
        ],
        'state_category' => [
            'type' => 'string',
            'values' => ['backlog', 'todo', 'in_progress', 'in_review', 'blocked', 'done', 'cancelled'],
            'triggers' => ['work_item.status_changed', 'work_item.assigned', 'work_item.created', 'schedule.due_soon', 'schedule.overdue'],
        ],
        'estimate_hours' => [
            'type' => 'number',
            'values' => null,
            'triggers' => ['work_item.status_changed', 'work_item.assigned', 'work_item.created', 'schedule.due_soon', 'schedule.overdue'],
        ],
        'project_id' => [
            'type' => 'id',
            'values' => null,
            'triggers' => ['work_item.status_changed', 'work_item.assigned', 'work_item.created', 'schedule.due_soon', 'schedule.overdue'],
        ],
        'assignee_membership_id' => [
            'type' => 'id',
            'values' => null,
            'triggers' => ['work_item.status_changed', 'work_item.assigned', 'work_item.created', 'schedule.due_soon', 'schedule.overdue'],
        ],
        'title' => [
            'type' => 'string',
            'values' => null,
            'triggers' => ['work_item.status_changed', 'work_item.assigned', 'work_item.created', 'schedule.due_soon', 'schedule.overdue'],
        ],

        // Transition-only facts. A condition naming one of these under any
        // other trigger is not wrong so much as unmatchable — the field is
        // absent, and the evaluator reads absent as "does not hold".
        'to_state_key' => [
            'type' => 'string',
            'values' => null,
            'triggers' => ['work_item.status_changed'],
        ],
        'from_state_key' => [
            'type' => 'string',
            'values' => null,
            'triggers' => ['work_item.status_changed'],
        ],
        'to_category' => [
            'type' => 'string',
            'values' => ['backlog', 'todo', 'in_progress', 'in_review', 'blocked', 'done', 'cancelled'],
            'triggers' => ['work_item.status_changed'],
        ],
        'from_category' => [
            'type' => 'string',
            'values' => ['backlog', 'todo', 'in_progress', 'in_review', 'blocked', 'done', 'cancelled'],
            'triggers' => ['work_item.status_changed'],
        ],
        'assigned_role' => [
            'type' => 'string',
            'values' => ['assignee', 'reviewer', 'watcher'],
            'triggers' => ['work_item.assigned'],
        ],
        // Computed for every work-item fact set, not only for the overdue
        // sweep — the listener derives it from `due_at` each time. Declaring it
        // as the scheduler's alone would hide "urgent and already three days
        // late" from every other trigger.
        'days_overdue' => [
            'type' => 'number',
            'values' => null,
            'triggers' => ['work_item.status_changed', 'work_item.assigned', 'work_item.created', 'schedule.due_soon', 'schedule.overdue'],
        ],
    ];

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return [
            'triggers' => WorkflowRuleModel::TRIGGERS,
            'operators' => ConditionEvaluator::OPERATORS,
            'actions' => ActionExecutor::types(),
            'fields' => self::FIELDS,
            // Who a `notify` action may reach. Never a person: a rule that
            // hardcodes a membership breaks the day they change teams, and
            // every customer discovers that the hard way.
            'audiences' => NotificationDispatcher::AUDIENCES,
        ];
    }

    public static function knows(string $field): bool
    {
        return array_key_exists($field, self::FIELDS);
    }
}

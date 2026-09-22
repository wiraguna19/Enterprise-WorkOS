<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Listener;

use App\Modules\Work\Domain\Event\WorkItemAssigned;
use App\Modules\Work\Domain\Event\WorkItemCreated;
use App\Modules\Work\Domain\Event\WorkItemStatusChanged;
use App\Modules\Workflow\Application\Service\WorkItemFacts;
use App\Modules\Workflow\Infrastructure\Job\EvaluateWorkflowRules;
use Illuminate\Support\Facades\DB;

/**
 * Turns domain events into queued rule evaluations.
 *
 * Thin on purpose: it gathers the facts a condition can be written against and
 * hands off. Anything more here would be workflow logic living outside the
 * workflow engine.
 *
 * The facts are gathered NOW rather than in the job, because by the time the
 * job runs the item may have moved again — and a rule about "when it became
 * in_review" must see the state at that moment, not the state later.
 */
final class DispatchRuleEvaluation
{
    public function __construct(
        // Shared with the manual run (ADR 0035): two fact builders that must
        // agree would disagree within a phase.
        private readonly WorkItemFacts $facts,
    ) {}

    public function onStatusChanged(WorkItemStatusChanged $event): void
    {
        $facts = $this->facts->for($event->workItemId) + [
            'to_category' => $event->toCategory,
            'from_state_id' => $event->fromStateId,
            'to_state_id' => $event->toStateId,
            // The reason the person gave for THIS move, which is the only
            // thing that can become an approval's submission note. Every other
            // fact here describes the item; this one describes the act.
            'comment' => $event->comment,
        ];

        $states = DB::table('workflow_states')
            ->whereIn('id', array_filter([$event->fromStateId, $event->toStateId]))
            ->pluck('key', 'id');

        $facts['from_state_key'] = $states[$event->fromStateId] ?? null;
        $facts['to_state_key'] = $states[$event->toStateId] ?? null;
        $facts['from_category'] = DB::table('workflow_states')
            ->where('id', $event->fromStateId)
            ->value('category');

        EvaluateWorkflowRules::dispatch(
            $event->organizationId,
            'work_item.status_changed',
            'work_item',
            $event->workItemId,
            $facts,
        );
    }

    public function onAssigned(WorkItemAssigned $event): void
    {
        EvaluateWorkflowRules::dispatch(
            $event->organizationId,
            'work_item.assigned',
            'work_item',
            $event->workItemId,
            $this->facts->for($event->workItemId) + ['assigned_role' => $event->role],
        );
    }

    public function onCreated(WorkItemCreated $event): void
    {
        EvaluateWorkflowRules::dispatch(
            $event->organizationId,
            'work_item.created',
            'work_item',
            $event->workItemId,
            $this->facts->for($event->workItemId),
        );
    }
}

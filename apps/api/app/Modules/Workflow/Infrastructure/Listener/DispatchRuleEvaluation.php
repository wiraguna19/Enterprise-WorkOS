<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Listener;

use App\Modules\Work\Domain\Event\WorkItemAssigned;
use App\Modules\Work\Domain\Event\WorkItemCreated;
use App\Modules\Work\Domain\Event\WorkItemStatusChanged;
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
    public function onStatusChanged(WorkItemStatusChanged $event): void
    {
        $facts = $this->factsFor($event->workItemId) + [
            'to_category' => $event->toCategory,
            'from_state_id' => $event->fromStateId,
            'to_state_id' => $event->toStateId,
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
            $this->factsFor($event->workItemId) + ['assigned_role' => $event->role],
        );
    }

    public function onCreated(WorkItemCreated $event): void
    {
        EvaluateWorkflowRules::dispatch(
            $event->organizationId,
            'work_item.created',
            'work_item',
            $event->workItemId,
            $this->factsFor($event->workItemId),
        );
    }

    /**
     * The vocabulary a condition may reference.
     *
     * Deliberately a flat map of scalars: conditions are customer-authored
     * data, and a nested object graph would need a path language nobody asked
     * for (docs/02 §7).
     *
     * @return array<string, mixed>
     */
    private function factsFor(string $workItemId): array
    {
        $item = DB::table('work_items')
            ->where('id', $workItemId)
            ->first([
                'id', 'type', 'reference', 'title', 'priority', 'state_category',
                'project_id', 'workflow_id', 'estimate_hours', 'due_at',
                'created_by_membership_id',
            ]);

        if ($item === null) {
            return [];
        }

        $assignee = DB::table('work_item_assignments')
            ->where('work_item_id', $workItemId)
            ->where('role', 'assignee')
            ->whereNull('unassigned_at')
            ->value('membership_id');

        $daysOverdue = $item->due_at === null
            ? null
            : (int) floor((time() - strtotime((string) $item->due_at)) / 86400);

        return [
            'type' => $item->type,
            'reference' => $item->reference,
            'title' => $item->title,
            'priority' => $item->priority,
            'state_category' => $item->state_category,
            'project_id' => $item->project_id,
            'workflow_id' => $item->workflow_id,
            'estimate_hours' => $item->estimate_hours,
            'assignee_membership_id' => $assignee,
            'created_by_membership_id' => $item->created_by_membership_id,
            'days_overdue' => max($daysOverdue ?? 0, 0),
        ];
    }
}

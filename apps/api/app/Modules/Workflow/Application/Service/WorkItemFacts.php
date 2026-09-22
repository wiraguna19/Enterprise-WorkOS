<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service;

use Illuminate\Support\Facades\DB;

/**
 * The vocabulary a rule's conditions may reference, about one work item.
 *
 * Extracted from `DispatchRuleEvaluation`, which built it privately, the day a
 * second caller appeared (ADR 0035): running a rule by hand has to see the same
 * world the engine sees, and two fact builders that must agree would disagree
 * within a phase — the rule that fired for the event would skip in the preview,
 * and the preview would be the thing people stopped trusting.
 *
 * Deliberately a flat map of scalars: conditions are customer-authored data,
 * and a nested object graph would need a path language nobody asked for
 * (docs/02 §7).
 */
final class WorkItemFacts
{
    /** @return array<string, mixed> */
    public function for(string $workItemId): array
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

    /**
     * The facts a rule's conditions ask for that describe the MOMENT rather
     * than the item (ADR 0035).
     *
     * `to_state_key`, `from_category`, `comment` and their kin exist only
     * because an event just happened. A rule run by hand has no such moment, so
     * a condition naming one of these cannot be evaluated honestly — and the
     * screen says which ones, rather than reporting a confident "did not match"
     * that sends somebody rewriting a rule that was never wrong.
     */
    public const MOMENT_ONLY = [
        'to_category', 'from_category', 'to_state_id', 'from_state_id',
        'to_state_key', 'from_state_key', 'comment', 'assigned_role',
    ];

    /**
     * Which moment-only facts this condition tree names.
     *
     * @param  array<string, mixed>  $condition
     * @return list<string>
     */
    public function momentOnlyFieldsIn(array $condition): array
    {
        $found = [];

        array_walk_recursive($condition, function (mixed $value, int|string $key) use (&$found): void {
            if ($key === 'field' && is_string($value) && in_array($value, self::MOMENT_ONLY, strict: true)) {
                $found[] = $value;
            }
        });

        return array_values(array_unique($found));
    }
}

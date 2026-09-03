<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Report;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * "What did I do" — the requester's own work in the window (docs/10).
 *
 * Always about the caller. There is no `membership` parameter, and adding one
 * would turn this into per-person delivery reporting, which `docs/02` §11 rules
 * out and ADR 0007 refuses to give a filter for. Someone who needs to see
 * another person's work already has the people directory and their workload,
 * both authorized per record.
 *
 * Read straight from the work item and its assignment rather than through the
 * flow query, for that reason precisely: `FlowQuery` has no assignee filter,
 * and the shortest route to adding one would have been to add it here.
 *
 * Hours are the logged actuals that the item's own rollup already maintains
 * (ADR 0003), not a second sum.
 */
final class PersonalReport implements ReportBuilder
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function columns(): array
    {
        return ['reference', 'title', 'project', 'state_category', 'due_at', 'completed_at', 'actual_hours'];
    }

    public function build(array $parameters): array
    {
        [$from, $to] = ReportWindow::from($parameters);

        /** @var list<object{reference: string, title: string, project: string|null, state_category: string, due_at: string|null, completed_at: string|null, actual_hours: string|float}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT w.reference,
                   w.title,
                   p.key AS project,
                   w.state_category,
                   w.due_at,
                   w.completed_at,
                   w.actual_hours_cache AS actual_hours
              FROM work_items w
              JOIN work_item_assignments a
                ON a.work_item_id = w.id
               AND a.organization_id = w.organization_id
               AND a.role = 'assignee'
         LEFT JOIN projects p
                ON p.id = w.project_id
               AND p.organization_id = w.organization_id
             WHERE w.organization_id = ?
               AND a.membership_id = ?
               AND w.deleted_at IS NULL
               -- Held during the window, whether or not it is finished: "what
               -- did I do" is answered as badly by only-completed work as by
               -- everything I have ever touched. An item that is still open and
               -- was worked on in the window belongs in the answer.
               AND (
                    (w.completed_at IS NOT NULL AND w.completed_at >= ? AND w.completed_at < ?)
                 OR (w.completed_at IS NULL AND a.assigned_at < ?)
               )
             ORDER BY w.completed_at DESC NULLS LAST, w.reference
        SQL, [
            $this->tenant->organizationId(),
            $this->tenant->membershipId(),
            $from->toDateTimeString(),
            $to->toDateTimeString(),
            $to->toDateTimeString(),
        ]);

        $mapped = array_map(static fn (object $row): array => [
            (string) $row->reference,
            (string) $row->title,
            $row->project === null ? null : (string) $row->project,
            (string) $row->state_category,
            $row->due_at === null ? null : (string) $row->due_at,
            $row->completed_at === null ? null : (string) $row->completed_at,
            (float) $row->actual_hours,
        ], $rows);

        // Nothing is withheld from a person about their own assignments: work
        // assigned to you is visible to you by clause 2 of the visibility rule,
        // so this count is zero by construction rather than by omission.
        return ['rows' => array_values($mapped), 'hidden_count' => 0];
    }
}

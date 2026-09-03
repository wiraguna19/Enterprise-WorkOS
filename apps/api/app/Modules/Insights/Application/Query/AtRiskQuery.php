<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Query;

use App\Modules\Organization\Application\Query\ReportingLine;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Where the risk is, exactly as ADR 0009 defines it.
 *
 * Five reasons, computed independently; a row carries every reason that applies
 * and is ordered by its worst. Not a risk score — the useful answer to "why is
 * this row here" is a reason, and a score answers "how bad" while refusing to
 * answer "why" (the same argument ADR 0008 makes about health).
 *
 * The order of the reasons is by how soon the consequence lands rather than by
 * how bad it feels: `overdue` is a commitment already missed, and `blocking`
 * outranks the rest because the cost is somebody else's week and it is the one
 * reason a manager cannot see by looking at the item alone.
 *
 * Capacity is deliberately NOT a reason here. An over-committed assignee would
 * attach the same reason to all twelve of their items and bury the four that
 * are really in trouble; capacity is a fact about a person and belongs in the
 * team capacity block, one row per person (ADR 0009).
 */
final class AtRiskQuery
{
    /** In progress with no transition for this long has stopped being progress. */
    private const STALLED_DAYS = 7;

    /** The categories where standing still is a problem rather than a queue. */
    private const WORKING_CATEGORIES = ['in_progress', 'in_review'];

    /** Unassigned only counts as risk once the date is close enough to hurt. */
    private const UNASSIGNED_DUE_WITHIN_DAYS = 7;

    /** Worst first; a row sorts by the first of these that applies to it. */
    private const SEVERITY = ['overdue', 'blocking', 'unassigned', 'stalled', 'blocked'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ReportingLine $reportingLine,
    ) {}

    /**
     * The rows this person is responsible for, worst first.
     *
     * Scope is decided by the data, not by a role name: work assigned to
     * someone in their reporting line, or work in a project they own or manage.
     * Nobody's Manager Home is selected by the word "manager" — roles are per
     * organization and customisable, and a screen keyed to the word breaks for
     * the first customer who renames it (ADR 0009, docs/06 §2).
     *
     * @return list<array{work_item_id: string, reasons: list<string>, blocking_count: int, days_since_move: int, due_at: string|null}>
     */
    public function forManager(string $membershipId, int $limit = 20): array
    {
        $line = $this->reportingLine->below($membershipId);
        $projects = $this->managedProjectIds($membershipId);

        /**
         * @var list<object{work_item_id: string, is_overdue: bool, blocking_count: int|string, is_unassigned: bool, days_since_move: int|string, is_blocked: bool, due_within_window: bool, due_at: string|null, state_category: string}> $rows
         */
        $rows = DB::select(<<<'SQL'
            -- The scope is a UNION of the two routes in, not an OR tested
            -- against every open item in the organization.
            --
            -- The OR shape was measured at 50 000 items and cost 397 ms on a
            -- sequential scan of `work_items` (perf:measure). It is the same
            -- defect global search had: Postgres can only bitmap-OR arms it can
            -- index, and "assigned to someone in my reporting line" is a
            -- correlated EXISTS. Driving each route from its own index and
            -- combining the ids afterwards is what makes both of them cheap.
            WITH scope_ids AS (
                -- Route one: work in a project I own or manage.
                --
                -- The project ids arrive as a bound ARRAY rather than as a
                -- subquery, because the planner needs to know there are three
                -- of them. Given a join against a subquery it guessed high,
                -- chose a sequential scan of `work_items` and paid 300 ms for
                -- it (perf:measure); given a short list it uses
                -- (organization_id, project_id) as intended. Same lesson as the
                -- search fix: give each route a shape its index can serve.
                SELECT w.id
                  FROM work_items w
                 WHERE w.organization_id = ?
                   AND w.project_id = ANY(?::uuid[])
                   AND w.deleted_at IS NULL
                   AND w.state_category NOT IN ('done','cancelled')
                UNION
                -- Route two: work held by someone in my reporting line, read
                -- FROM the assignments rather than by asking every open item
                -- whether one of my reports holds it.
                SELECT a.work_item_id
                  FROM work_item_assignments a
                  JOIN work_items w
                    ON w.id = a.work_item_id
                   AND w.organization_id = a.organization_id
                 WHERE a.organization_id = ?
                   AND a.unassigned_at IS NULL
                   AND a.role = 'assignee'
                   AND a.membership_id = ANY(?::uuid[])
                   AND w.deleted_at IS NULL
                   AND w.state_category NOT IN ('done','cancelled')
            ),
            scope AS (
                SELECT w.id, w.state_category, w.due_at, w.created_at
                  FROM work_items w
                  JOIN scope_ids s ON s.id = w.id
                 WHERE w.organization_id = ?
            ),
            candidates AS (
                SELECT s.id AS work_item_id,

                       s.due_at,
                       s.state_category,

                       (s.due_at IS NOT NULL AND s.due_at < now()) AS is_overdue,

                       -- How many OPEN items are waiting on this one. A
                       -- dependency from finished work is history, not risk.
                       (SELECT count(*)
                          FROM work_item_dependencies d
                          JOIN work_items blocked_item
                            ON blocked_item.id = d.work_item_id
                           AND blocked_item.organization_id = d.organization_id
                         WHERE d.depends_on_work_item_id = s.id
                           AND d.organization_id = ?
                           AND d.type = 'blocks'
                           AND blocked_item.deleted_at IS NULL
                           AND blocked_item.state_category NOT IN ('done','cancelled')
                       ) AS blocking_count,

                       NOT EXISTS (
                           SELECT 1
                             FROM work_item_assignments a
                            WHERE a.work_item_id = s.id
                              AND a.organization_id = ?
                              AND a.unassigned_at IS NULL
                              AND a.role = 'assignee'
                       ) AS is_unassigned,

                       -- Measured from creation when the item has never moved
                       -- at all, so a request nobody has touched since it
                       -- arrived is stalled rather than invisible.
                       floor(
                           EXTRACT(EPOCH FROM (now() - coalesce(m.moved_at, s.created_at))) / 86400.0
                       )::int AS days_since_move,

                       (s.state_category = 'blocked') AS is_blocked,

                       -- Close enough that nobody can still pick it up in time.
                       (s.due_at IS NOT NULL
                        AND s.due_at < now() + (? || ' days')::interval) AS due_within_window

                  FROM scope s
             -- LATERAL, per scope row, rather than one aggregate over the whole
             -- transitions table joined back. Measured at 50 000 items: the
             -- aggregate made the planner scan every partition of
             -- `work_item_transitions` — 433 ms in a single statement — because
             -- nothing in it constrains `occurred_at`, so there is no partition
             -- to prune and no reason for it to prefer the per-item index.
             --
             -- This asks the question the index `idx_wit_item` was built for:
             -- (organization_id, work_item_id, occurred_at DESC), one lookup
             -- per item in scope. The scope is one person's projects and
             -- reports, so that is a handful of lookups rather than a read of
             -- the whole history of the organization.
             LEFT JOIN LATERAL (
                 -- Only the latest move of any kind: how long a BLOCK has
                 -- lasted is project health's question (ADR 0008), not this
                 -- one, and the old aggregate computed it here for nobody.
                 SELECT max(t.occurred_at) AS moved_at
                   FROM work_item_transitions t
                  WHERE t.organization_id = ?
                    AND t.work_item_id = s.id
             ) m ON true
            )
            SELECT *
              FROM candidates
             -- Only rows that will produce at least one reason come back. This
             -- repeats the rules in reasonsFor(), which is a duplication with a
             -- safe direction: this filter may only ever be LOOSER than the PHP
             -- one. A row that arrives with no reason is skipped there; a row
             -- filtered out here that should have had one would be invisible,
             -- so nothing may be added to this WHERE that is not also in the
             -- PHP.
             WHERE is_overdue
                OR blocking_count > 0
                OR (is_unassigned AND due_within_window)
                OR is_blocked
                OR (state_category IN ('in_progress','in_review')
                    AND days_since_move >= ?)
        SQL, [
            // Positional bindings, named here because their ORDER is the whole
            // contract and moving one line of SQL silently reassigns them.
            $this->tenant->organizationId(),   // scope route one: my projects
            '{'.implode(',', $projects).'}',   // …the projects themselves
            $this->tenant->organizationId(),   // scope route two: my line
            '{'.implode(',', $line).'}',       // …the line itself
            $this->tenant->organizationId(),   // scope hydration
            $this->tenant->organizationId(),   // blocking_count
            $this->tenant->organizationId(),   // is_unassigned
            self::UNASSIGNED_DUE_WITHIN_DAYS,  // due_within_window
            $this->tenant->organizationId(),   // the LATERAL move lookup
            self::STALLED_DAYS,                // the final filter
        ]);

        $risky = [];

        foreach ($rows as $row) {
            $reasons = $this->reasonsFor($row);

            if ($reasons === []) {
                continue;
            }

            $risky[] = [
                'work_item_id' => (string) $row->work_item_id,
                'reasons' => $reasons,
                'blocking_count' => (int) $row->blocking_count,
                'days_since_move' => (int) $row->days_since_move,
                'due_at' => $row->due_at === null ? null : (string) $row->due_at,
            ];
        }

        // Sorted here rather than in SQL: the ordering is by the severity of a
        // reason LIST, which is this class's definition and not the database's.
        // What the database now does is filter — only rows that will produce at
        // least one reason come back — so this sorts a handful of rows rather
        // than every open item in the scope.
        //
        // Worst reason first, then the soonest date: two overdue items are not
        // equally urgent, and a tie broken arbitrarily makes the list reshuffle
        // between page loads for no reason the reader can see.
        usort($risky, static fn (array $a, array $b): int => [
            self::rank($a['reasons']),
            $a['due_at'] ?? '9999',
        ] <=> [
            self::rank($b['reasons']),
            $b['due_at'] ?? '9999',
        ]);

        return array_slice($risky, 0, $limit);
    }

    /**
     * The projects this person owns or manages, read once.
     *
     * In PHP rather than as a CTE so the ids can be BOUND into the scope query
     * — see the comment there. It is one small indexed read either way; what
     * changes is what the planner knows when it decides how to find the work.
     *
     * @return list<string>
     */
    private function managedProjectIds(string $membershipId): array
    {
        /** @var list<object{id: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT p.id
              FROM projects p
             WHERE p.organization_id = ?
               AND p.deleted_at IS NULL
               AND (
                    p.owner_membership_id = ?
                 OR EXISTS (
                        SELECT 1
                          FROM project_members pm
                         WHERE pm.project_id = p.id
                           AND pm.organization_id = p.organization_id
                           AND pm.membership_id = ?
                           AND pm.role IN ('owner','manager')
                           AND pm.removed_at IS NULL
                    )
               )
        SQL, [$this->tenant->organizationId(), $membershipId, $membershipId]);

        return array_values(array_map(static fn (object $row): string => (string) $row->id, $rows));
    }

    /**
     * @param  object{is_overdue: bool, blocking_count: int|string, is_unassigned: bool, days_since_move: int|string, is_blocked: bool, due_within_window: bool, state_category: string}  $row
     * @return list<string>
     */
    private function reasonsFor(object $row): array
    {
        $reasons = [];

        if ((bool) $row->is_overdue) {
            $reasons[] = 'overdue';
        }

        if ((int) $row->blocking_count > 0) {
            $reasons[] = 'blocking';
        }

        // Unassigned work with no date is a backlog, not a risk. It becomes one
        // when the date is close enough that nobody can still pick it up in
        // time.
        if ((bool) $row->is_unassigned && (bool) $row->due_within_window) {
            $reasons[] = 'unassigned';
        }

        // Only work that is supposed to be MOVING can stall (ADR 0009). A todo
        // item that has sat for a month is a backlog, which is a prioritisation
        // fact and not a risk — and counting it here would put most of a real
        // organization's work on the risk list, which is the same as putting
        // none of it there. Blocked work is excluded by the same clause: it is
        // waiting on something named, a different problem with a different fix.
        if (in_array($row->state_category, self::WORKING_CATEGORIES, strict: true)
            && (int) $row->days_since_move >= self::STALLED_DAYS
        ) {
            $reasons[] = 'stalled';
        }

        if ((bool) $row->is_blocked) {
            $reasons[] = 'blocked';
        }

        return $reasons;
    }

    /** @param  list<string>  $reasons */
    private static function rank(array $reasons): int
    {
        foreach (self::SEVERITY as $index => $reason) {
            if (in_array($reason, $reasons, strict: true)) {
                return $index;
            }
        }

        return count(self::SEVERITY);
    }
}

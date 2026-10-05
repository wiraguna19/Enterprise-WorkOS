<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Kpi;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The computed KPI sources, with ADR 0007's and ADR 0010's definitions and no
 * new ones (ADR 0062).
 *
 * The SQL mirrors FlowQuery::completions — last completion in the window per
 * item, first start over all history — with a scope added. It is not a call to
 * FlowQuery because the scopes differ (a team, a department's whole subtree, a
 * person), and threading four optional filters through the flow report's query
 * would put the report's correctness at the mercy of the KPI's needs.
 */
final class KpiMetrics
{
    public const SOURCES = ['throughput', 'cycle_time_p85', 'on_time_rate'];

    /** The unit each computed source is reported in. */
    public const UNITS = [
        'throughput' => 'items',
        'cycle_time_p85' => 'hours',
        'on_time_rate' => 'percent',
    ];

    /** Which way is better, fixed by what the number is. */
    public const DIRECTIONS = [
        'throughput' => 'higher',
        'cycle_time_p85' => 'lower',
        'on_time_rate' => 'higher',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  list<string>|null  $departmentIds  for a department: it and everything below it
     */
    public function value(string $source, string $subjectType, string $subjectId, KpiPeriod $period, ?array $departmentIds = null): ?float
    {
        [$scope, $bindings] = $this->scope($subjectType, $subjectId, $departmentIds);
        $organizationId = $this->tenant->organizationId();
        $from = $period->start->toDateTimeString();
        $to = $period->end()->toDateTimeString();

        if ($source === 'throughput') {
            // Every entry into done counts, a reopened item on each completion
            // (ADR 0007, "Throughput").
            /** @var object{n: int|string}|null $row */
            $row = DB::selectOne(<<<SQL
                SELECT count(*) AS n
                  FROM work_item_transitions t
                  JOIN work_items w ON w.id = t.work_item_id AND w.organization_id = t.organization_id
             LEFT JOIN projects p ON p.id = w.project_id AND p.organization_id = w.organization_id
                 WHERE t.organization_id = ?
                   AND t.to_category = 'done'
                   AND t.occurred_at >= ?
                   AND t.occurred_at <  ?
                   AND w.deleted_at IS NULL
                   AND {$scope}
            SQL, [$organizationId, $from, $to, ...$bindings]);

            return (float) ($row->n ?? 0);
        }

        $rows = $this->completions($subjectType, $subjectId, $period->start, $period->end(), $departmentIds);

        if ($source === 'cycle_time_p85') {
            $hours = [];

            foreach ($rows as $row) {
                if ($row['hours'] !== null) {
                    $hours[] = $row['hours'];
                }
            }

            return self::nearestRank($hours, 0.85);
        }

        // on_time_rate: undated work is outside the denominator (ADR 0010).
        $dated = 0;
        $onTime = 0;

        foreach ($rows as $row) {
            if ($row['late'] === null) {
                continue;
            }

            $dated++;
            $onTime += $row['late'] ? 0 : 1;
        }

        return $dated === 0 ? null : round($onTime / $dated * 100, 1);
    }

    /**
     * One row per item whose LAST completion falls in the window: what it
     * took (first start to that completion) and whether it was late. The
     * cycle time and the on-time rate are both folded from this, and so is
     * the person delivery panel and the list behind it — so a figure and its
     * evidence cannot disagree.
     *
     * @param  list<string>|null  $departmentIds
     * @return list<array{work_item_id: string, reference: string, title: string, completed_at: string, due_at: string|null, hours: float|null, late: bool|null}>
     */
    public function completions(string $subjectType, string $subjectId, CarbonImmutable $from, CarbonImmutable $to, ?array $departmentIds = null): array
    {
        [$scope, $bindings] = $this->scope($subjectType, $subjectId, $departmentIds);
        $organizationId = $this->tenant->organizationId();

        /** @var list<object{work_item_id: string, reference: string, title: string, completed_at: string, due_at: string|null, hours: string|float|null, late: bool|null}> $rows */
        $rows = DB::select(<<<SQL
            WITH done AS (
                SELECT t.work_item_id, max(t.occurred_at) AS completed_at
                  FROM work_item_transitions t
                  JOIN work_items w ON w.id = t.work_item_id AND w.organization_id = t.organization_id
             LEFT JOIN projects p ON p.id = w.project_id AND p.organization_id = w.organization_id
                 WHERE t.organization_id = ?
                   AND t.to_category = 'done'
                   AND t.occurred_at >= ?
                   AND t.occurred_at <  ?
                   AND w.deleted_at IS NULL
                   AND {$scope}
                 GROUP BY t.work_item_id
            ),
            started AS (
                SELECT t.work_item_id, min(t.occurred_at) AS started_at
                  FROM work_item_transitions t
                 WHERE t.organization_id = ?
                   AND t.to_category = 'in_progress'
                   AND t.work_item_id IN (SELECT work_item_id FROM done)
                 GROUP BY t.work_item_id
            )
            SELECT d.work_item_id,
                   w.reference,
                   w.title,
                   d.completed_at,
                   w.due_at,
                   CASE
                       WHEN s.started_at IS NULL OR s.started_at > d.completed_at THEN NULL
                       ELSE EXTRACT(EPOCH FROM (d.completed_at - s.started_at)) / 3600.0
                   END AS hours,
                   CASE WHEN w.due_at IS NULL THEN NULL ELSE d.completed_at > w.due_at END AS late
              FROM done d
              JOIN work_items w ON w.id = d.work_item_id
         LEFT JOIN started s ON s.work_item_id = d.work_item_id
             ORDER BY d.completed_at DESC
        SQL, [$organizationId, $from->toDateTimeString(), $to->toDateTimeString(), ...$bindings, $organizationId]);

        return array_values(array_map(static fn (object $row): array => [
            'work_item_id' => (string) $row->work_item_id,
            'reference' => (string) $row->reference,
            'title' => (string) $row->title,
            'completed_at' => CarbonImmutable::parse((string) $row->completed_at)->toIso8601String(),
            'due_at' => $row->due_at === null ? null : CarbonImmutable::parse((string) $row->due_at)->toIso8601String(),
            'hours' => $row->hours === null ? null : round((float) $row->hours, 2),
            'late' => $row->late === null ? null : (bool) $row->late,
        ], $rows));
    }

    /**
     * The WHERE fragment for a subject, over `w` (work_items) and `p` (projects).
     *
     * @param  list<string>|null  $departmentIds
     * @return array{0: string, 1: list<string>}
     */
    private function scope(string $subjectType, string $subjectId, ?array $departmentIds): array
    {
        return match ($subjectType) {
            'project' => ['w.project_id = ?::uuid', [$subjectId]],
            'department' => $departmentIds === null || $departmentIds === []
                ? ['false', []]
                : ['p.department_id IN ('.implode(',', array_fill(0, count($departmentIds), '?::uuid')).')', $departmentIds],
            // Work whose assignee is a current member of the team.
            'team' => [<<<'SQL'
                EXISTS (
                    SELECT 1 FROM work_item_assignments a
                      JOIN team_members tm ON tm.membership_id = a.membership_id
                     WHERE a.work_item_id = w.id
                       AND a.role = 'assignee'
                       AND a.unassigned_at IS NULL
                       AND tm.team_id = ?::uuid
                       AND tm.left_at IS NULL
                )
                SQL, [$subjectId]],
            // ADR 0062, "Per person": their own assigned work. Visibility of
            // the KPI itself is decided elsewhere, by the reporting line.
            'person' => [<<<'SQL'
                EXISTS (
                    SELECT 1 FROM work_item_assignments a
                     WHERE a.work_item_id = w.id
                       AND a.role = 'assignee'
                       AND a.unassigned_at IS NULL
                       AND a.membership_id = ?::uuid
                )
                SQL, [$subjectId]],
            default => ['false', []],
        };
    }

    /**
     * Nearest-rank, as FlowQuery: every value it returns is a duration some
     * item actually took.
     *
     * @param  list<float>  $values
     */
    private static function nearestRank(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return round($values[max(0, (int) ceil($p * count($values)) - 1)], 1);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Query;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cycle time and throughput, exactly as ADR 0007 defines them.
 *
 * Read entirely from `work_item_transitions`, which has been append-only since
 * Phase 4 for this purpose (docs/03 §4). Nothing new is written and nothing is
 * backfilled; the history was already being collected.
 *
 * The definition, restated because a metric class should not require opening
 * another file to be read:
 *
 *   cycle time = first entry into `in_progress` → last entry into `done`,
 *                per item, blocked time included, cancelled items excluded,
 *                a reopened item measured once across the whole span
 *   throughput = count of entries into `done` in the period
 *
 * Reported as p50 and p85 with the sample size, never as a mean: cycle-time
 * distributions are skewed, and a mean lands somewhere no actual item ever was.
 *
 * Never broken down per person. `docs/02` §11 rules out reducing individual
 * performance to a ranked number, and per-assignee cycle time is that number
 * wearing a neutral name.
 */
final class FlowQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function between(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $projectId = null,
    ): array {
        $completions = $this->completions($from, $to, $projectId);

        $durations = array_values(array_filter(
            array_map(static fn (array $row): ?float => $row['hours'], $completions),
            static fn (?float $hours): bool => $hours !== null,
        ));

        $weeks = [];

        foreach ($completions as $row) {
            $week = CarbonImmutable::parse($row['completed_at'])->startOfWeek()->toDateString();

            $weeks[$week] ??= ['week_start' => $week, 'throughput' => 0, 'hours' => []];
            $weeks[$week]['throughput']++;

            if ($row['hours'] !== null) {
                $weeks[$week]['hours'][] = $row['hours'];
            }
        }

        ksort($weeks);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),

            'throughput' => count($completions),

            // Sample size travels with every figure. A thin period should be
            // recognisable as thin rather than as fast.
            'measured' => count($durations),
            // Completions with no `in_progress` transition to measure from.
            // Excluded from the percentiles, never silently counted as zero.
            'unmeasurable' => count($completions) - count($durations),

            'cycle_time_p50_hours' => self::percentile($durations, 0.50),
            'cycle_time_p85_hours' => self::percentile($durations, 0.85),

            'weeks' => array_values(array_map(static fn (array $week): array => [
                'week_start' => $week['week_start'],
                'throughput' => $week['throughput'],
                'measured' => count($week['hours']),
                'cycle_time_p50_hours' => self::percentile($week['hours'], 0.50),
                'cycle_time_p85_hours' => self::percentile($week['hours'], 0.85),
            ], $weeks)),
        ];
    }

    /**
     * One row per completion, with the hours it took or null if unmeasurable.
     *
     * The percentiles and the weekly rows are both folded from THIS, so the
     * headline figure and its breakdown cannot disagree — the same rule the
     * workload drill-through follows (docs/10, Phase 6).
     *
     * @return list<array{work_item_id: string, reference: string, completed_at: string, hours: float|null}>
     */
    public function completions(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $projectId = null,
    ): array {
        /**
         * Shape declared for the same reason WorkItemVisibility declares its
         * recursive-CTE rows: PDO hands back a bare object, and without this
         * PHPStan cannot tell a renamed column from a typo. `hours` arrives as
         * a string from EXTRACT, which is why the cast below is not decorative.
         *
         * @var list<object{work_item_id: string, reference: string, completed_at: string, hours: string|float|null}> $rows
         */
        $rows = DB::select(<<<'SQL'
            WITH done AS (
                -- The LAST completion inside the window, per item. An item that
                -- was reopened and closed again is one row here: measured once,
                -- first start to last done (ADR 0007).
                SELECT t.work_item_id, max(t.occurred_at) AS completed_at
                  FROM work_item_transitions t
                  JOIN work_items w
                    ON w.id = t.work_item_id
                   AND w.organization_id = t.organization_id
                 WHERE t.organization_id = ?
                   AND t.to_category = 'done'
                   AND t.occurred_at >= ?
                   AND t.occurred_at <  ?
                   AND w.deleted_at IS NULL
                   AND (?::uuid IS NULL OR w.project_id = ?::uuid)
                 GROUP BY t.work_item_id
            ),
            started AS (
                -- The FIRST time work actually began, over all history rather
                -- than only inside the window: an item finished on Monday may
                -- have started last quarter, and clamping the start to the
                -- window would report a cycle time of a few days for it.
                SELECT t.work_item_id, min(t.occurred_at) AS started_at
                  FROM work_item_transitions t
                 WHERE t.organization_id = ?
                   AND t.to_category = 'in_progress'
                 GROUP BY t.work_item_id
            )
            SELECT d.work_item_id,
                   w.reference,
                   d.completed_at,
                   CASE
                       WHEN s.started_at IS NULL OR s.started_at > d.completed_at THEN NULL
                       ELSE EXTRACT(EPOCH FROM (d.completed_at - s.started_at)) / 3600.0
                   END AS hours
              FROM done d
              JOIN work_items w ON w.id = d.work_item_id
         LEFT JOIN started s ON s.work_item_id = d.work_item_id
             ORDER BY d.completed_at
        SQL, [
            $this->tenant->organizationId(),
            $from->toDateTimeString(),
            $to->toDateTimeString(),
            $projectId,
            $projectId,
            $this->tenant->organizationId(),
        ]);

        return array_values(array_map(static fn (object $row): array => [
            'work_item_id' => (string) $row->work_item_id,
            'reference' => (string) $row->reference,
            'completed_at' => (string) $row->completed_at,
            'hours' => $row->hours === null ? null : round((float) $row->hours, 2),
        ], $rows));
    }

    /**
     * The nearest-rank percentile.
     *
     * Nearest-rank rather than interpolated: every value it returns is a
     * duration some item actually took, which is the property that makes "p85
     * is 40 hours" a sentence a team can make a promise from.
     *
     * @param  list<float>  $values
     */
    private static function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        $rank = (int) ceil($p * count($values));

        return round($values[max($rank, 1) - 1], 2);
    }
}

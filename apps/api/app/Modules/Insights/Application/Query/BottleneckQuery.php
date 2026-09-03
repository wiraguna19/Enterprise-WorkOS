<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Query;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Where work waited, exactly as ADR 0010 defines it.
 *
 * Two consecutive transitions bound one step: the item entered a state category
 * at the first and left it at the second. A bottleneck row is one category with
 * the median time items spent there and the number sitting in it right now.
 *
 * **Not sorted by queue length.** A backlog with two hundred items in it is not
 * a bottleneck — the backlog is where work is supposed to wait. A review step
 * with a four-day median is. Sorted by the wait, descending.
 *
 * Reported per CATEGORY, not per workflow state: states are customisable per
 * organization (docs/02 §7) while the seven categories are closed, so a
 * per-state table would not be comparable between two customers or even between
 * two workflows in one. A per-state view is a later drill-down inside a
 * category, not a replacement for this.
 */
final class BottleneckQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return list<array{category: string, median_hours: float|null, p85_hours: float|null, steps: int, waiting_now: int}>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var list<object{category: string, median_hours: string|float|null, p85_hours: string|float|null, steps: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            WITH steps AS (
                -- One row per completed stay in a category. `lead` gives the
                -- moment the item left; the last transition of an item has no
                -- lead and so no duration, which is correct — that stay has not
                -- finished (ADR 0010).
                SELECT t.to_category AS category,
                       t.occurred_at AS entered_at,
                       lead(t.occurred_at) OVER (
                           PARTITION BY t.work_item_id ORDER BY t.occurred_at
                       ) AS left_at
                  FROM work_item_transitions t
                  JOIN work_items w
                    ON w.id = t.work_item_id
                   AND w.organization_id = t.organization_id
                 WHERE t.organization_id = ?
                   AND w.deleted_at IS NULL
            )
            SELECT category,

                   -- percentile_disc, not percentile_cont: it returns a value
                   -- from the set, so every figure here is a wait something
                   -- actually had. Same reason ADR 0007 uses nearest rank.
                   percentile_disc(0.50) WITHIN GROUP (
                       ORDER BY EXTRACT(EPOCH FROM (left_at - entered_at)) / 3600.0
                   ) AS median_hours,

                   percentile_disc(0.85) WITHIN GROUP (
                       ORDER BY EXTRACT(EPOCH FROM (left_at - entered_at)) / 3600.0
                   ) AS p85_hours,

                   count(*) AS steps

              FROM steps
             WHERE left_at IS NOT NULL
               -- Counted when the wait ENDED, matching throughput's rule of
               -- counting the moment the thing happened.
               AND left_at >= ?
               AND left_at <  ?
             GROUP BY category
        SQL, [$this->tenant->organizationId(), $from->toDateTimeString(), $to->toDateTimeString()]);

        $waiting = $this->waitingNow();

        $bottlenecks = array_map(static fn (object $row): array => [
            'category' => (string) $row->category,
            'median_hours' => $row->median_hours === null ? null : round((float) $row->median_hours, 2),
            'p85_hours' => $row->p85_hours === null ? null : round((float) $row->p85_hours, 2),
            'steps' => (int) $row->steps,
            'waiting_now' => 0,
        ], $rows);

        foreach ($bottlenecks as $index => $row) {
            $bottlenecks[$index]['waiting_now'] = $waiting[$row['category']] ?? 0;
        }

        // A category nothing has left in the window but where work is sitting
        // right now still belongs on this list — that is the shape of a step
        // things go into and do not come out of, which is the worst bottleneck
        // there is and the one a "median wait" can never show.
        foreach ($waiting as $category => $count) {
            if (! in_array($category, array_column($bottlenecks, 'category'), strict: true)) {
                $bottlenecks[] = [
                    'category' => (string) $category,
                    'median_hours' => null,
                    'p85_hours' => null,
                    'steps' => 0,
                    'waiting_now' => $count,
                ];
            }
        }

        // Longest wait first, and a category with no measured wait sorts below
        // every category that has one — `-1` is not a duration, it is "there is
        // nothing to compare here", and it keeps those rows at the bottom
        // rather than at the top where a null would put them.
        usort($bottlenecks, static fn (array $a, array $b): int => [
            $b['median_hours'] ?? -1,
            $b['waiting_now'],
        ] <=> [
            $a['median_hours'] ?? -1,
            $a['waiting_now'],
        ]);

        return $bottlenecks;
    }

    /**
     * How many open items are sitting in each category right now.
     *
     * A snapshot, and the one figure on this list that changes without any work
     * happening — it changes when time passes. The page says so.
     *
     * @return array<string, int>
     */
    private function waitingNow(): array
    {
        /** @var list<object{state_category: string, waiting: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT state_category, count(*) AS waiting
              FROM work_items
             WHERE organization_id = ?
               AND deleted_at IS NULL
               AND state_category NOT IN ('done','cancelled')
             GROUP BY state_category
        SQL, [$this->tenant->organizationId()]);

        $waiting = [];

        foreach ($rows as $row) {
            $waiting[(string) $row->state_category] = (int) $row->waiting;
        }

        return $waiting;
    }
}

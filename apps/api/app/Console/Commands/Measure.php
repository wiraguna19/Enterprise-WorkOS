<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Insights\Application\Query\AtRiskQuery;
use App\Modules\Insights\Application\Query\BottleneckQuery;
use App\Modules\Insights\Application\Query\FlowQuery;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Search\Domain\Contract\SearchDriver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Answers the performance questions this product has been asserting without
 * evidence (docs/10 Phase 5 exit criteria, docs/01 §8, docs/11 §5).
 *
 * Deliberately a command and not a test. `docs/11` §5 puts load testing in k6
 * for a reason: a stopwatch assertion in CI measures the CI machine's mood, and
 * one that runs at seed scale measures nothing at all — Postgres picks a
 * sequential scan on a few hundred rows and is fast whether or not the index it
 * is supposed to use exists.
 *
 * So this reports two things per query, and a person reads them:
 *
 *   - **what the planner chose**, which is the question a small dataset cannot
 *     answer and a large one answers definitively;
 *   - **how long it took**, over several runs, because one run measures the
 *     cache state and not the query.
 *
 * Run `perf:seed-volume` first, or the output will be a page of numbers that
 * look excellent and mean nothing.
 *
 * Lives in `app/Console/Commands` rather than in a module, and that is the
 * point: it reaches across Search and Insights at once, and deptrac covers
 * `app/Modules` only. Putting it inside Insights would have meant adding an
 * `Insights → Search` edge to the module graph to accommodate a measuring
 * tape — a boundary bent for something that is not part of the product.
 */
final class Measure extends Command
{
    protected $signature = 'perf:measure
        {--runs=5 : How many times to run each query}
        {--as=rina@acme.test : Whose eyes to measure through}';

    protected $description = 'Report query plans and timings for search and the Insights reads';

    public function handle(
        TenantContext $tenant,
        SearchDriver $search,
        FlowQuery $flow,
        BottleneckQuery $bottlenecks,
        AtRiskQuery $atRisk,
    ): int {
        $membership = DB::table('memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('users.email', (string) $this->option('as'))
            ->select('memberships.id', 'memberships.organization_id')
            ->first();

        if ($membership === null) {
            $this->error('No membership for '.(string) $this->option('as').'.');

            return self::FAILURE;
        }

        $items = DB::table('work_items')
            ->where('organization_id', $membership->organization_id)
            ->count();

        $this->line("Measuring against <options=bold>{$items}</> work items, as ".(string) $this->option('as'));

        if ($items < 10_000) {
            // Said plainly rather than left for the reader to infer from a
            // suspiciously good number.
            $this->warn('That is seed scale. Postgres will pick sequential scans and every');
            $this->warn('figure below will be fast for the wrong reason. Run work:seed-volume.');
        }

        $this->newLine();

        $runs = max(1, (int) $this->option('runs'));

        $tenant->runForMembership(
            (string) $membership->organization_id,
            (string) $membership->id,
            function () use ($search, $flow, $bottlenecks, $atRisk, $membership, $runs): void {
                $to = CarbonImmutable::now();
                $from = $to->subWeeks(12);

                $this->time('search "migration"', $runs, fn () => $search->search('migration', ['work_item'], 20));
                $this->time('search by reference', $runs, fn () => $search->search('VOL-4242', ['work_item'], 20));
                $this->time('flow, 12 weeks', $runs, fn () => $flow->between($from, $to));
                $this->time('bottlenecks, 12 weeks', $runs, fn () => $bottlenecks->between($from, $to));
                $this->time('at-risk', $runs, fn () => $atRisk->forManager((string) $membership->id));
            },
        );

        $this->newLine();
        $this->plans();

        return self::SUCCESS;
    }

    private function time(string $label, int $runs, callable $work): void
    {
        $timings = [];

        for ($i = 0; $i < $runs; $i++) {
            $started = hrtime(true);
            $work();
            $timings[] = (hrtime(true) - $started) / 1_000_000;
        }

        sort($timings);

        // The median and the worst, not the mean: one cold run dominates a mean
        // and hides it, and the tail is the number a budget is written against
        // (docs/01 §8 is a p95 table).
        $median = $timings[(int) floor(count($timings) / 2)];
        $worst = $timings[count($timings) - 1];

        $this->line(sprintf(
            '  %-24s median %7.1f ms   worst %7.1f ms',
            $label,
            $median,
            $worst,
        ));
    }

    /**
     * The half a timing cannot tell you.
     *
     * A query that is fast because the table is small looks exactly like one
     * that is fast because it used the right index, and a slow one tells you
     * nothing about WHICH part is slow. The search predicate is taken apart
     * here for that reason: Postgres can only use a bitmap OR when EVERY arm of
     * the OR is indexable, so one unindexable arm makes the whole search a
     * sequential scan no matter how good the other two are.
     */
    private function plans(): void
    {
        $organizationId = (string) DB::table('work_items')->value('organization_id');

        $this->line('<options=bold>Plans</> — what the planner actually chose:');

        $checks = [
            'search: full text alone' => "EXPLAIN SELECT id FROM work_items
                WHERE organization_id = ?::uuid
                  AND search_vector @@ websearch_to_tsquery('english', 'migration')",

            'search: reference alone' => "EXPLAIN SELECT id FROM work_items
                WHERE organization_id = ?::uuid
                  AND upper(reference) = upper('VOL-4242')",

            'search: as the driver builds it' => "EXPLAIN SELECT id FROM work_items
                WHERE organization_id = ?::uuid
                  AND (
                       search_vector @@ websearch_to_tsquery('english', 'migration')
                    OR upper(reference) = upper('migration')
                  )",

            'at-risk: the scope scan' => "EXPLAIN SELECT w.id
                FROM work_items w
           LEFT JOIN projects p ON p.id = w.project_id AND p.organization_id = w.organization_id
               WHERE w.organization_id = ?::uuid
                 AND w.deleted_at IS NULL
                 AND w.state_category NOT IN ('done','cancelled')
                 AND EXISTS (SELECT 1 FROM work_item_assignments a
                              WHERE a.work_item_id = w.id
                                AND a.organization_id = w.organization_id
                                AND a.unassigned_at IS NULL
                                AND a.role = 'assignee')",

            'overdue work' => "EXPLAIN SELECT id FROM work_items
                WHERE organization_id = ?::uuid AND due_at < now()
                  AND state_category NOT IN ('done','cancelled') AND deleted_at IS NULL
                ORDER BY due_at",
        ];

        foreach ($checks as $label => $sql) {
            $plan = collect(DB::select($sql, [$organizationId]))
                ->pluck('QUERY PLAN')
                ->implode(' ');

            $scan = str_contains($plan, 'Seq Scan') ? '<fg=yellow>sequential scan</>' : '<fg=green>index</>';

            $index = [];

            foreach (['idx_wi_search', 'uq_work_items_reference', 'idx_wi_overdue', 'idx_wi_org_state_due'] as $candidate) {
                if (str_contains($plan, $candidate)) {
                    $index[] = $candidate;
                }
            }

            $this->line(sprintf(
                '  %-32s %s%s',
                $label,
                $scan,
                $index === [] ? '' : '  ('.implode(', ', $index).')',
            ));
        }

        $this->newLine();
        $this->line('A sequential scan is not automatically wrong: a predicate that matches half');
        $this->line('the table is cheaper to scan, and at seed scale everything is. It is the');
        $this->line('combination that matters — a selective predicate scanning the whole table,');
        $this->line('or an OR whose arms are individually indexed and jointly are not.');
    }
}

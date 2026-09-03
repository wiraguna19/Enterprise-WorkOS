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
use Throwable;

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

        $this->line(sprintf('  %-24s median %7.1f ms   worst %7.1f ms', $label, $median, $worst));

        $this->explainSlowestStatement($work);
    }

    /**
     * Run it once more with the query log on, and explain whichever statement
     * actually took the longest — as the database itself times it.
     *
     * Written this way after a hand-copied EXPLAIN misled me: the plan probe
     * for the risk query described the shape the code USED to have, so it kept
     * reporting a sequential scan after the query had been rewritten. A plan
     * typed out beside the code it describes drifts from it exactly like every
     * other duplicated definition in this codebase. Nothing is transcribed
     * here; whatever the operation issued is what gets explained.
     *
     * `EXPLAIN ANALYZE`, not `EXPLAIN`: an estimated plan says which access
     * paths were chosen and nothing about where the time went. "Sequential scan
     * on a 50 000-row table" sounds like the answer and is worth about 20 ms —
     * so a 317 ms statement that mentions one is still unexplained, and the
     * node that actually cost the time is usually somewhere else.
     */
    private function explainSlowestStatement(callable $work): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $work();

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        if ($log === []) {
            return;
        }

        usort($log, static fn (array $a, array $b): int => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
        $slowest = $log[0];

        try {
            $rows = DB::select(
                'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$slowest['query'],
                $slowest['bindings'],
            );

            /** @var list<array<string, mixed>> $plan */
            $plan = json_decode((string) ($rows[0]->{'QUERY PLAN'} ?? '[]'), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->line('      (could not explain: '.$e->getMessage().')');

            return;
        }

        $nodes = [];
        $this->walk($plan[0]['Plan'] ?? [], $nodes);

        // Sorted by the time a node cost IN TOTAL — its own time multiplied by
        // how many times it ran. A node that takes 0.2 ms and runs fifty
        // thousand times is the answer, and it looks harmless in every other
        // view of a plan.
        usort($nodes, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $this->line(sprintf(
            '      slowest statement %6.1f ms of %d',
            (float) ($slowest['time'] ?? 0),
            count($log),
        ));

        foreach (array_slice($nodes, 0, 3) as $node) {
            $this->line(sprintf(
                '        %7.1f ms  %s%s%s%s',
                $node['total'],
                $node['type'],
                $node['relation'] === null ? '' : ' on '.$node['relation'],
                $node['loops'] > 1 ? sprintf('  ×%d loops', $node['loops']) : '',
                $node['read'] + $node['hit'] > 0
                    ? sprintf('  [%d pages, %d from disk]', $node['read'] + $node['hit'], $node['read'])
                    : '',
            ));
        }
    }

    /**
     * Flatten the plan tree, keeping what a person needs to read it.
     *
     * @param  array<string, mixed>  $node
     * @param  list<array{type: string, relation: string|null, total: float, loops: int, read: int, hit: int}>  $into
     */
    private function walk(array $node, array &$into): void
    {
        if ($node === []) {
            return;
        }

        $loops = (int) ($node['Actual Loops'] ?? 1);
        $perLoop = (float) ($node['Actual Total Time'] ?? 0);

        $into[] = [
            'type' => (string) ($node['Node Type'] ?? 'unknown'),
            'relation' => isset($node['Relation Name']) ? (string) $node['Relation Name'] : null,
            'total' => $perLoop * max($loops, 1),
            'loops' => $loops,
            // Pages read from disk versus found in cache. The difference
            // between "this scan is expensive" and "this scan is expensive the
            // first time", which a timing alone cannot tell you.
            'read' => (int) ($node['Shared Read Blocks'] ?? 0),
            'hit' => (int) ($node['Shared Hit Blocks'] ?? 0),
        ];

        /** @var list<array<string, mixed>> $children */
        $children = $node['Plans'] ?? [];

        foreach ($children as $child) {
            $this->walk($child, $into);
        }
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

        // These three are deliberate ISOLATION probes, not copies of a query
        // the product runs: they take the search predicate apart to show which
        // arm can use an index on its own. Anything measuring a real operation
        // explains the statement that operation actually issued (see
        // explainSlowestStatement).
        $this->line('<options=bold>Probes</> — the search predicate, arm by arm:');

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

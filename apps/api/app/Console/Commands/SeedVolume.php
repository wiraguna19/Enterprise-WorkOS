<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The volume fixture every performance claim in this product has been waiting
 * for (docs/11 §5, docs/10 Phase 5 exit criteria).
 *
 * Phase 5 claims global search returns in under 300 ms. Phase 6 added query
 * budgets. **None of it has ever been measured against real volume**, and it
 * cannot be at seed scale: Postgres correctly picks a sequential scan on a few
 * hundred rows and is fast with or without the GIN indexes, so a timing
 * assertion there would pass whether the index existed or not. That is a green
 * test that proves nothing, which is worse than no test.
 *
 * This generates the rows so the claim can be checked. It is a TOOL, not a
 * test: `insights:measure` reports what the planner chose and how long it took,
 * and a human reads it. A stopwatch assertion in CI would measure the CI
 * machine's mood (docs/11 §5 puts load testing in k6, deliberately).
 *
 * Everything it writes carries the `VOL-` reference prefix, so `--purge`
 * removes exactly what it made and nothing a person created — except the
 * transitions, which no application code may delete (see `purge()`); that is
 * the append-only rule working, not a gap in this command.
 *
 * Outside the modules on purpose: fixture generation is not part of the
 * product, and deptrac covers `app/Modules` only.
 *
 * One caveat to read the results with: these rows get `gen_random_uuid()` (v4)
 * primary keys while the product writes UUIDv7, which is ordered. Index
 * locality on inserts is therefore WORSE here than in production; read timings
 * as a floor, not a forecast.
 *
 * Written as `INSERT ... SELECT generate_series(...)`: fifty thousand rows in
 * one statement, seconds rather than the twenty minutes an ORM loop would take.
 * The generated text is deliberately varied — a table of fifty thousand rows
 * all titled "Test" would make every full-text search either match everything
 * or nothing, and prove nothing about relevance or about the index.
 */
final class SeedVolume extends Command
{
    protected $signature = 'perf:seed-volume
        {--items=50000 : How many work items to generate}
        {--organization= : Which organization; defaults to the seeded Acme}
        {--purge : Delete everything a previous run generated, and stop}';

    protected $description = 'Generate a large body of work items for performance measurement';

    /** Everything this command writes is findable by this prefix. */
    private const REFERENCE_PREFIX = 'VOL-';

    private const ACME = '01900000-0000-7000-8000-0000000000ac';

    /**
     * The categories the generated items are spread across, in the order the
     * SQL's modulo indexes them. `cancelled` is left out on purpose: cancelled
     * work is excluded from every Phase 6 metric, so generating it would add
     * rows that no measurement here would ever read.
     */
    private const CATEGORIES = ['backlog', 'todo', 'in_progress', 'in_review', 'blocked', 'done'];

    public function handle(): int
    {
        if (app()->environment('production')) {
            // Not a guard against malice — a guard against a tired evening and
            // the wrong shell tab.
            $this->error('Refusing to generate fixture data in production.');

            return self::FAILURE;
        }

        $organizationId = (string) ($this->option('organization') ?? self::ACME);

        if ($this->option('purge')) {
            return $this->purge($organizationId);
        }

        $items = max(1, (int) $this->option('items'));

        $project = DB::table('projects')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->first();

        // Resolved to a plain category → id map here rather than passed around
        // as a collection of rows: every use below needs one specific category,
        // and a `first()` fallback for a missing one would generate fifty
        // thousand rows in the wrong state and call it success.
        $stateIds = [];

        foreach (DB::table('workflow_states')->where('organization_id', $organizationId)->get() as $state) {
            $stateIds[(string) $state->category] ??= (string) $state->id;
        }

        $missing = array_values(array_diff(self::CATEGORIES, array_keys($stateIds)));

        if ($project === null || $missing !== []) {
            $this->error($project === null
                ? 'That organization has no project. Run the seeders first.'
                : 'That organization has no workflow state for: '.implode(', ', $missing).'.');

            return self::FAILURE;
        }

        $this->info("Generating {$items} work items…");

        $this->generateItems($organizationId, (string) $project->id, (string) $project->workflow_id, $stateIds, $items);
        $this->generateTransitions($organizationId, $stateIds);

        $this->analyze();
        $this->reportPartitioning($organizationId);

        $this->newLine();
        $this->info('Done. Measure with: php artisan perf:measure');
        $this->line('Remove with:      php artisan perf:seed-volume --purge');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $stateIds  workflow state id, by category
     */
    private function generateItems(
        string $organizationId,
        string $projectId,
        string $workflowId,
        array $stateIds,
        int $count,
    ): void {
        // A vocabulary rather than one repeated string: full-text search over
        // fifty thousand identical titles tells you nothing about either the
        // index or relevance.
        $subjects = "ARRAY['authentication','billing','migration','dashboard','webhook','scheduler','import','export','permission','notification','search','calendar','workflow','attachment','session']";
        $verbs = "ARRAY['fix','investigate','refactor','document','ship','revert','profile','harden','simplify','instrument']";
        $qualifiers = "ARRAY['for the mobile client','after the tenant split','on the reporting path','under load','for large tenants','in the audit trail','behind the feature flag','without downtime']";

        $categories = "ARRAY['".implode("','", self::CATEGORIES)."']";

        // The two arrays are indexed by the same modulo below, so their order
        // has to match: item n gets category[n % 6] and the state id that
        // belongs to it. Built from one list for that reason.
        $stateArray = 'ARRAY['.implode(',', array_map(
            static fn (string $category): string => "'".$stateIds[$category]."'::uuid",
            self::CATEGORIES,
        )).']';

        DB::statement(<<<SQL
            INSERT INTO work_items (
                id, organization_id, type, reference, title, description,
                project_id, workflow_id, workflow_state_id, state_category,
                priority, due_at, estimate_hours, completed_at, created_at, updated_at
            )
            SELECT
                gen_random_uuid(),
                ?::uuid,
                'task',
                -- Unique per organization, and identifiable: everything this
                -- command writes can be found and removed by this prefix.
                '{$this->prefix()}' || n,
                (SELECT v FROM unnest({$verbs}) WITH ORDINALITY AS t(v, i) WHERE i = 1 + (n % 10))
                  || ' ' ||
                (SELECT s FROM unnest({$subjects}) WITH ORDINALITY AS t(s, i) WHERE i = 1 + (n % 15))
                  || ' ' ||
                (SELECT q FROM unnest({$qualifiers}) WITH ORDINALITY AS t(q, i) WHERE i = 1 + (n % 8)),
                'Generated for volume measurement. Item number ' || n || '.',
                ?::uuid,
                ?::uuid,
                ({$stateArray})[1 + (n % 6)],
                ({$categories})[1 + (n % 6)],
                (ARRAY['low','medium','high','urgent'])[1 + (n % 4)],
                -- Dates spread across a year on both sides of today, so the
                -- overdue index and the flow window have something to select.
                now() - ((n % 365) || ' days')::interval + ((n % 90) || ' days')::interval,
                (1 + (n % 16))::numeric,
                -- The CHECK requires a done item to carry a completion time.
                CASE WHEN (n % 6) = 5
                     THEN now() - ((n % 300) || ' days')::interval
                     ELSE NULL END,
                now() - ((n % 400) || ' days')::interval,
                now()
              FROM generate_series(1, ?) AS n
        SQL, [$organizationId, $projectId, $workflowId, $count]);
    }

    /**
     * @param  array<string, string>  $stateIds  workflow state id, by category
     */
    private function generateTransitions(string $organizationId, array $stateIds): void
    {
        $this->info('Generating transitions for the completed ones…');

        // Only the completed items get a history, and each gets the two moves
        // ADR 0007 measures between: cycle time is first `in_progress` to last
        // `done`, and an item with no start is deliberately unmeasurable.
        DB::statement(<<<'SQL'
            INSERT INTO work_item_transitions (
                id, organization_id, work_item_id, to_state_id, to_category, cause, occurred_at
            )
            -- No created_at: this table is append-only and partitioned by
            -- occurred_at, which is the only time it keeps. `system` rather
            -- than the `user` default, because nobody moved these.
            SELECT gen_random_uuid(), ?::uuid, w.id, ?::uuid, 'in_progress', 'system',
                   w.completed_at - ((1 + (abs(hashtext(w.id::text)) % 240)) || ' hours')::interval
              FROM work_items w
             WHERE w.organization_id = ?::uuid
               AND w.reference LIKE ?
               AND w.completed_at IS NOT NULL
        SQL, [$organizationId, $stateIds['in_progress'], $organizationId, self::REFERENCE_PREFIX.'%']);

        DB::statement(<<<'SQL'
            INSERT INTO work_item_transitions (
                id, organization_id, work_item_id, to_state_id, to_category, cause, occurred_at
            )
            SELECT gen_random_uuid(), ?::uuid, w.id, ?::uuid, 'done', 'system', w.completed_at
              FROM work_items w
             WHERE w.organization_id = ?::uuid
               AND w.reference LIKE ?
               AND w.completed_at IS NOT NULL
        SQL, [$organizationId, $stateIds['done'], $organizationId, self::REFERENCE_PREFIX.'%']);
    }

    /**
     * Hand the planner statistics for the rows just written.
     *
     * Bulk inserts leave a table with no up-to-date statistics until autovacuum
     * gets round to it, and a planner with no statistics guesses — badly, and
     * usually towards a sequential scan. Measuring before this runs measures
     * the planner's ignorance rather than the product: the first run of this
     * fixture reported a sequential scan over the GIN index and a 315 ms
     * search, which is what an unanalyzed table looks like.
     *
     * Part of generating the fixture, not an optional extra: a fixture that
     * cannot be measured correctly is not a fixture.
     */
    private function analyze(): void
    {
        $this->info('Updating planner statistics…');

        DB::statement('ANALYZE work_items');
        DB::statement('ANALYZE work_item_transitions');
    }

    /**
     * Say how much of the generated history missed a real partition.
     *
     * `work_item_transitions` is partitioned by month, and the partitions were
     * created from one month before the migration ran onwards. Generated
     * history reaches a year back, so the older rows land in the DEFAULT
     * partition — where the planner cannot prune, and the flow and bottleneck
     * reads are therefore SLOWER here than in a production database that has
     * accumulated its months as time passed.
     *
     * Printed rather than fixed: creating the missing partitions would fail
     * anyway, because the default partition already holds rows that would
     * violate the new bounds. It matters only that nobody reads a pessimistic
     * number as the product's real one.
     */
    private function reportPartitioning(string $organizationId): void
    {
        $inDefault = DB::table('work_item_transitions_default')
            ->where('organization_id', $organizationId)
            ->count();

        if ($inDefault === 0) {
            return;
        }

        $this->newLine();
        $this->warn("{$inDefault} transitions landed in the DEFAULT partition — older than the");
        $this->warn('oldest monthly partition, so the planner cannot prune them. Flow and');
        $this->warn('bottleneck timings will be pessimistic against production.');
    }

    /**
     * Removes the work items. The transitions stay, and that is the product
     * working.
     *
     * `work_item_transitions` carries a BEFORE UPDATE OR DELETE trigger that
     * refuses both: it is the evidence cycle time is computed from, and
     * evidence the application can rewrite is not evidence. A fixture command
     * is still the application, so it does not get an exception — the
     * alternative would be shipping a way to erase history and trusting
     * everyone to only use it on their laptop.
     *
     * There is no foreign key from the transitions to the items (a partitioned
     * table cannot easily carry one), so deleting the items succeeds and leaves
     * the transitions pointing at ids that no longer exist. Every read in the
     * product joins `work_items`, so they are invisible to it — they occupy
     * space, and nothing else.
     */
    private function purge(string $organizationId): int
    {
        $orphaned = DB::table('work_item_transitions')
            ->whereIn('work_item_id', fn ($q) => $q
                ->from('work_items')
                ->select('id')
                ->where('organization_id', $organizationId)
                ->where('reference', 'like', self::REFERENCE_PREFIX.'%'))
            ->count();

        $items = DB::table('work_items')
            ->where('organization_id', $organizationId)
            ->where('reference', 'like', self::REFERENCE_PREFIX.'%')
            ->delete();

        $this->info("Removed {$items} generated work items.");

        if ($orphaned > 0) {
            $this->newLine();
            $this->warn("{$orphaned} transitions remain: that table is append-only, on purpose,");
            $this->warn('and refuses DELETE from the application — a fixture command included.');
            $this->warn('They reference items that no longer exist, so every read in the product');
            $this->warn('skips them. For a genuinely clean database: php artisan migrate:fresh --seed');
        }

        return self::SUCCESS;
    }

    private function prefix(): string
    {
        return self::REFERENCE_PREFIX;
    }
}

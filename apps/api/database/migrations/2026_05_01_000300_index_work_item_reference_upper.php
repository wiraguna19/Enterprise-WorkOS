<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The index global search needed and did not have.
 *
 * Measured, not guessed (`perf:seed-volume` + `perf:measure` at 50 000 items):
 *
 *   search: full text alone          index  (idx_wi_search)
 *   search: reference alone          sequential scan
 *   search: as the driver builds it  sequential scan
 *
 * The driver matches a reference with `upper(reference) = upper(?)`, because
 * people paste "ENG-142" in any case. `uq_work_items_reference` is built on the
 * raw column, so that arm had no index — and since Postgres can only bitmap-OR
 * arms it can all index, ONE unindexable arm made the whole search a sequential
 * scan of `work_items`. The tell was in the timings before the plans: two
 * queries with wildly different selectivity both took ~300 ms, which is what a
 * full scan looks like.
 *
 * This is exactly the defect a seed-scale test cannot find. At a few hundred
 * rows the planner correctly picks a sequential scan for everything, so a
 * timing assertion there passes whether or not this index exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Organization first: every search is scoped to one tenant, so the
            -- leading column is the one that cuts the table down.
            CREATE INDEX idx_wi_reference_upper
                ON work_items (organization_id, upper(reference));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS idx_wi_reference_upper;');
    }
};

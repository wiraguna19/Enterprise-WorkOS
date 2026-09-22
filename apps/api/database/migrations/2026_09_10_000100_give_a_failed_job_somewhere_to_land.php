<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Somewhere for a failed job to land (ADR 0037).
 *
 * `config/queue.php` has pointed at `failed_jobs` since the framework was
 * installed, and the table has never existed. A job that exhausts its retries
 * is handed to the failure store, the store's INSERT fails, and the job is gone
 * — payload, exception, timing, all of it. The queue is where this product does
 * its rule evaluation, its notifications and its recurrence materialising, so
 * "a job died and nobody can say which, or why" is not a small gap.
 *
 * It was found from the outside: a rule showed three failures, the run log
 * showed none, and the question "where did those attempts go" had no table to
 * ask (ADR 0036).
 *
 * The shape is Laravel's own, because the framework writes these rows and
 * `queue:retry` reads them. `uuid` rather than an auto-increment id: the
 * configured driver is `database-uuids`, which is what makes a failed job
 * addressable by a stable name in `queue:retry <uuid>`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE failed_jobs (
                id          bigserial   PRIMARY KEY,
                uuid        varchar(255) NOT NULL,
                connection  text         NOT NULL,
                queue       text         NOT NULL,
                payload     text         NOT NULL,
                exception   text         NOT NULL,
                failed_at   timestamptz  NOT NULL DEFAULT now()
            );

            CREATE UNIQUE INDEX uq_failed_jobs_uuid ON failed_jobs (uuid);

            -- Read by the only question anybody asks of this table: what has
            -- been failing lately.
            CREATE INDEX idx_failed_jobs_failed_at ON failed_jobs (failed_at DESC);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS failed_jobs;');
    }
};

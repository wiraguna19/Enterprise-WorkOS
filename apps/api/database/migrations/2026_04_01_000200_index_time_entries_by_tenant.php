<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The tenant index `time_entries` was missing (docs/03 §0).
 *
 * The table itself has existed since Phase 2, built with the feature in mind
 * and left unused until now. Every other tenant-scoped table carries
 * `uq_<table>_org_id ON (organization_id, id)`, which is what makes a composite
 * foreign key TO it possible — and composite foreign keys are how a
 * cross-tenant reference fails on a constraint rather than on application care.
 *
 * Nothing references a time entry today. This is here so that the first thing
 * that does — an invoice line, an approved timesheet — is not blocked by a
 * missing index, and so the convention holds without exceptions to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_time_entries_org_id
                ON time_entries (organization_id, id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS uq_time_entries_org_id;');
    }
};

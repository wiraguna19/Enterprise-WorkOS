<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who made this rule run (ADR 0035).
 *
 * Every run in this table until now was the system's own doing: an event
 * happened, the engine evaluated its rules. A person can now run one by hand,
 * and the run log has to be able to say so — otherwise the screen that exists
 * to answer "why did this work item move?" answers "a rule did it" and hides
 * the fact that somebody pressed a button a second earlier.
 *
 * NULL is the system, which is what every existing row is, and no backfill is
 * needed to say that truthfully.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE workflow_rule_runs
                ADD COLUMN triggered_by_membership_id uuid NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE workflow_rule_runs DROP COLUMN IF EXISTS triggered_by_membership_id;');
    }
};

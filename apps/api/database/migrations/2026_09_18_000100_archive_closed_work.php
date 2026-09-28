<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Closed work leaves the working set (docs/10 Phase 7 "archival strategy for
 * closed work", ADR 0054).
 *
 * Work items are never deleted in the ordinary course: done is done, and the
 * history, the reports and the comments all hang off the row. So `work_items`
 * only grows, and every screen that shows WORK — a board's Done column, the
 * browse list, a project's items — pays for every item ever finished. The
 * volume fixture found the board falling over at 50 000 rows (a bound fixed
 * it); the Done column is where the next one lives.
 *
 * ## Archived is a flag on the row, not a second table
 *
 * Moving rows out would break every foreign key that points at them —
 * comments, files, transitions, approvals, time entries, dependencies — and
 * the reports that count completions over a quarter would have to read two
 * tables. A timestamp keeps the row where it is and lets the working-set
 * screens ignore it through a partial index that stays the size of the work
 * still in play.
 *
 * ## Only closed work, and reopening undoes it
 *
 * The CHECK says an archived item is done or cancelled. The trigger makes
 * reopening clear the flag, whichever path reopens it — a transition, a board
 * drag, a rule, the workflow editor recategorising a state — so no writer has
 * to remember, and the CHECK can never be tripped by one that forgot.
 *
 * ## Each organization says when
 *
 * `organizations.archive_closed_after_days`: closed and untouched for this
 * many days, then archived by `work:archive-closed-work`. Ninety by default;
 * null means never.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE work_items ADD COLUMN archived_at timestamptz NULL;

            ALTER TABLE work_items ADD CONSTRAINT ck_wi_archived_is_closed
                CHECK (archived_at IS NULL OR state_category IN ('done', 'cancelled'));

            -- OR REPLACE, like every function in this schema: `migrate:fresh`
            -- drops tables and leaves functions behind, so a plain CREATE
            -- passes on a new database and fails on every rebuild after it —
            -- which is how the test suite found it.
            CREATE OR REPLACE FUNCTION work_items_unarchive_when_reopened() RETURNS trigger
                LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.archived_at IS NOT NULL
                   AND NEW.state_category NOT IN ('done', 'cancelled') THEN
                    NEW.archived_at := NULL;
                END IF;

                RETURN NEW;
            END
            $$;

            CREATE TRIGGER trg_wi_unarchive_when_reopened
                BEFORE UPDATE OF state_category ON work_items
                FOR EACH ROW EXECUTE FUNCTION work_items_unarchive_when_reopened();

            -- The working set of a project: what a board and a project list
            -- read. Stays the size of the work in play however much is done.
            CREATE INDEX idx_wi_project_live
                ON work_items (organization_id, project_id, position)
                WHERE deleted_at IS NULL AND archived_at IS NULL;

            -- What the sweep looks for, and nothing else.
            CREATE INDEX idx_wi_archive_candidates
                ON work_items (organization_id, updated_at)
                WHERE archived_at IS NULL
                  AND deleted_at IS NULL
                  AND state_category IN ('done', 'cancelled');

            ALTER TABLE organizations
                ADD COLUMN archive_closed_after_days integer NULL DEFAULT 90;

            ALTER TABLE organizations ADD CONSTRAINT ck_org_archive_days
                CHECK (archive_closed_after_days IS NULL
                       OR archive_closed_after_days BETWEEN 7 AND 3650);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations DROP CONSTRAINT IF EXISTS ck_org_archive_days;
            ALTER TABLE organizations DROP COLUMN IF EXISTS archive_closed_after_days;
            DROP INDEX IF EXISTS idx_wi_archive_candidates;
            DROP INDEX IF EXISTS idx_wi_project_live;
            DROP TRIGGER IF EXISTS trg_wi_unarchive_when_reopened ON work_items;
            DROP FUNCTION IF EXISTS work_items_unarchive_when_reopened();
            ALTER TABLE work_items DROP CONSTRAINT IF EXISTS ck_wi_archived_is_closed;
            ALTER TABLE work_items DROP COLUMN IF EXISTS archived_at;
        SQL);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recurring work (docs/03 §4, docs/10 Phase 5).
 *
 * `rrule` holds an RFC 5545 string, because scheduling is a solved problem and
 * a home-grown DSL would be a decade of edge cases (docs/03 §4). The template
 * is the work item to create, stored as JSON rather than as a nullable copy of
 * every work_items column — a recurrence describes *what to create*, and half a
 * work item's columns (state, position, reference) only exist once it does.
 *
 * `next_run_at` is materialised state, not a derived value read from the rule.
 * It is what makes the scheduler a cheap indexed lookup instead of an
 * expansion of every rule on every tick, and it is what a row lock can be taken
 * on so two workers cannot create the same occurrence twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE recurrences (
                id                 uuid          PRIMARY KEY,
                organization_id    uuid          NOT NULL
                                   REFERENCES organizations (id) ON DELETE CASCADE,

                -- Who set it up. Kept for the audit question "why does this
                -- appear every Monday", which is asked long after the person
                -- who knows has moved on.
                created_by_membership_id uuid    NULL,

                rrule              varchar(500)  NOT NULL,
                template           jsonb         NOT NULL,

                -- Both nullable-free by intent: a recurrence that has never run
                -- still knows when it next should, and one that has stopped is
                -- deactivated rather than left with a null nobody can read.
                next_run_at        timestamptz   NOT NULL,
                last_run_at        timestamptz   NULL,
                ends_at            timestamptz   NULL,
                is_active          boolean       NOT NULL DEFAULT true,

                created_at         timestamptz   NOT NULL DEFAULT now(),
                updated_at         timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT fk_recurrences_creator
                    FOREIGN KEY (organization_id, created_by_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE SET NULL
            );

            CREATE UNIQUE INDEX uq_recurrences_org_id
                ON recurrences (organization_id, id);

            -- The scheduler's only query: everything due, across every tenant,
            -- oldest first. Partial, because an inactive recurrence is dead
            -- weight in an index that is read every few minutes.
            CREATE INDEX idx_recurrences_due
                ON recurrences (next_run_at)
                WHERE is_active;

            -- work_items.recurrence_id has existed since Phase 2 with nothing to
            -- point at. Now that there is, it gets the composite foreign key
            -- every tenant reference here has, so a cross-tenant link fails on
            -- the constraint rather than on application care (docs/03 §0).
            ALTER TABLE work_items
                ADD CONSTRAINT fk_work_items_recurrence
                FOREIGN KEY (organization_id, recurrence_id)
                REFERENCES recurrences (organization_id, id) ON DELETE SET NULL;

            CREATE INDEX idx_wi_recurrence
                ON work_items (recurrence_id)
                WHERE recurrence_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_wi_recurrence;
            ALTER TABLE work_items DROP CONSTRAINT IF EXISTS fk_work_items_recurrence;
            DROP TABLE IF EXISTS recurrences;
        SQL);
    }
};

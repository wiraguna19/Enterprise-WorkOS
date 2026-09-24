<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The projects one person keeps in their sidebar (docs/08 §1, ADR 0044).
 *
 * docs/08 §1 draws a `PROJECTS` section in the sidebar and states the rule:
 * *"Projects and Teams are pinned lists, not full trees. Users pin the 3–7
 * they actually work in."* Only TEAMS was ever built.
 *
 * ## Why a table rather than deriving the list
 *
 * The cheap version is "show the projects this person can see, capped at six".
 * For an employee that is nearly right, because visibility follows membership.
 * For anyone holding `project.view_all` it is **every internal project**, so
 * the six shown are whichever six sort first — and those are exactly the people
 * with the most projects. docs/08 §1's own warning is that a sidebar listing
 * 200 projects is a sidebar nobody reads; six arbitrary ones is the same
 * sentence with a smaller number.
 *
 * A pin is a CHOICE, and a choice needs somewhere to live.
 *
 * ## Per membership, not per user
 *
 * Somebody who belongs to two organizations pins different things in each, and
 * the row is deleted with the membership rather than outliving it in an
 * organization they have left. It is also what makes the table tenant-scoped
 * like everything else here.
 *
 * ## No FK to a soft-deleted project, and that is deliberate
 *
 * `projects` is soft-deleted, so a CASCADE fires only on a hard delete, which
 * this product does not do. A pin whose project was archived stays: archiving
 * is reversible (ADR 0040), and dropping the pin would quietly punish somebody
 * for putting a project away for a month. The QUERY filters archived projects
 * out of the sidebar; the row waits.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE pinned_projects (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL
                                REFERENCES organizations (id) ON DELETE CASCADE,
                membership_id   uuid         NOT NULL,
                project_id      uuid         NOT NULL,

                -- Where it sits in the person's own list. Fractional would be
                -- over-engineering for a list docs/08 caps at about seven: a
                -- reorder rewrites every row and there are never many.
                position        integer      NOT NULL DEFAULT 0,

                created_at      timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT ck_pinned_projects_position
                    CHECK (position >= 0),

                CONSTRAINT fk_pinned_projects_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_pinned_projects_project
                    FOREIGN KEY (organization_id, project_id)
                    REFERENCES projects (organization_id, id) ON DELETE CASCADE
            );

            -- Pinning the same project twice is not a second pin. The unique
            -- index is what makes "pin" idempotent at the database rather than
            -- at whichever caller remembers to check first.
            CREATE UNIQUE INDEX uq_pinned_projects_once
                ON pinned_projects (membership_id, project_id);

            -- The sidebar reads this on EVERY authenticated request. It is the
            -- one query here that has to be free.
            CREATE INDEX idx_pinned_projects_mine
                ON pinned_projects (organization_id, membership_id, position);

            CREATE UNIQUE INDEX uq_pinned_projects_org_id
                ON pinned_projects (organization_id, id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS pinned_projects CASCADE;');
    }
};

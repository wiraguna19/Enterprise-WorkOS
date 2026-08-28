<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Projects are CONTAINERS of work, not work items (docs/02 §5).
 *
 * They live in their own table because their lifecycle, cardinality, and
 * permission semantics are different: few, long-lived, membership-scoped, and
 * they act as a permission boundary. Modelling a project as "a work item that
 * contains work items" is elegant on a whiteboard and awkward everywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE projects (
                id                  uuid          PRIMARY KEY,
                organization_id     uuid          NOT NULL
                                    REFERENCES organizations (id) ON DELETE CASCADE,
                key                 varchar(12)   NOT NULL,
                name                varchar(160)  NOT NULL,
                description         text          NOT NULL DEFAULT '',
                owner_membership_id uuid          NULL,
                department_id       uuid          NULL,
                workflow_id         uuid          NULL,
                status              varchar(20)   NOT NULL DEFAULT 'planning',
                priority            varchar(10)   NOT NULL DEFAULT 'medium',
                visibility          varchar(10)   NOT NULL DEFAULT 'internal',
                start_date          date          NULL,
                end_date            date          NULL,
                budget_amount       numeric(15,2) NULL,
                budget_currency     char(3)       NULL,

                -- Progress is DERIVED from the project's work items and cached
                -- here by a queued job. It is never authoritative: a stored
                -- percentage that can drift from the work it describes is worse
                -- than an expensive query (docs/02 §5, docs/12 §8).
                progress_cache      numeric(5,2)  NOT NULL DEFAULT 0,
                progress_cached_at  timestamptz   NULL,

                -- Optimistic locking: two managers editing the same project get
                -- a 409 and a merge prompt, not a lost update (docs/03 §0).
                lock_version        integer       NOT NULL DEFAULT 0,

                created_at          timestamptz   NOT NULL DEFAULT now(),
                updated_at          timestamptz   NOT NULL DEFAULT now(),
                archived_at         timestamptz   NULL,
                deleted_at          timestamptz   NULL,

                CONSTRAINT ck_projects_status
                    CHECK (status IN ('planning','active','on_hold','completed','cancelled')),
                CONSTRAINT ck_projects_priority
                    CHECK (priority IN ('low','medium','high','urgent')),
                CONSTRAINT ck_projects_visibility
                    CHECK (visibility IN ('internal','private')),
                CONSTRAINT ck_projects_dates
                    CHECK (start_date IS NULL OR end_date IS NULL OR start_date <= end_date),
                CONSTRAINT ck_projects_progress
                    CHECK (progress_cache >= 0 AND progress_cache <= 100),
                CONSTRAINT ck_projects_budget_currency
                    CHECK ((budget_amount IS NULL) = (budget_currency IS NULL)),
                CONSTRAINT ck_projects_key_format
                    CHECK (key ~ '^[A-Z][A-Z0-9]{1,11}$'),

                CONSTRAINT fk_projects_owner
                    FOREIGN KEY (organization_id, owner_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE SET NULL,
                CONSTRAINT fk_projects_department
                    FOREIGN KEY (organization_id, department_id)
                    REFERENCES departments (organization_id, id) ON DELETE SET NULL,
                CONSTRAINT fk_projects_workflow
                    FOREIGN KEY (organization_id, workflow_id)
                    REFERENCES workflows (organization_id, id) ON DELETE SET NULL
            );

            CREATE UNIQUE INDEX uq_projects_org_id ON projects (organization_id, id);

            -- The project key prefixes every work item reference (ENG-142), so
            -- it must stay unique among live projects. A deleted project's key
            -- is released deliberately: its references are already historical.
            CREATE UNIQUE INDEX uq_projects_org_key
                ON projects (organization_id, key) WHERE deleted_at IS NULL;

            CREATE INDEX idx_projects_status
                ON projects (organization_id, status) WHERE deleted_at IS NULL AND archived_at IS NULL;
            CREATE INDEX idx_projects_department
                ON projects (organization_id, department_id);
        SQL);

        // ── project members ─────────────────────────────────────────────────
        // Project membership is a visibility and permission boundary
        // (docs/06 §2), not decoration — which is why it carries a role and
        // closes rather than deletes.
        DB::unprepared(<<<'SQL'
            CREATE TABLE project_members (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL,
                project_id      uuid         NOT NULL,
                membership_id   uuid         NULL,
                team_id         uuid         NULL,
                role            varchar(20)  NOT NULL DEFAULT 'member',
                added_by        uuid         NULL,
                added_at        timestamptz  NOT NULL DEFAULT now(),
                removed_at      timestamptz  NULL,

                CONSTRAINT ck_project_members_role
                    CHECK (role IN ('owner','manager','member','viewer')),
                -- A row grants access to EITHER a person or a whole team,
                -- never both and never neither.
                CONSTRAINT ck_project_members_subject
                    CHECK ((membership_id IS NULL) <> (team_id IS NULL)),

                CONSTRAINT fk_project_members_project
                    FOREIGN KEY (organization_id, project_id)
                    REFERENCES projects (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_project_members_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_project_members_team
                    FOREIGN KEY (organization_id, team_id)
                    REFERENCES teams (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_project_members_active_person
                ON project_members (project_id, membership_id)
                WHERE removed_at IS NULL AND membership_id IS NOT NULL;
            CREATE UNIQUE INDEX uq_project_members_active_team
                ON project_members (project_id, team_id)
                WHERE removed_at IS NULL AND team_id IS NOT NULL;

            -- "Which projects can this person see?" runs on every list request.
            CREATE INDEX idx_project_members_membership
                ON project_members (organization_id, membership_id)
                WHERE removed_at IS NULL;
        SQL);

        // ── milestones ──────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TABLE milestones (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL,
                project_id      uuid         NOT NULL,
                name            varchar(160) NOT NULL,
                description     text         NOT NULL DEFAULT '',
                due_date        date         NULL,
                status          varchar(20)  NOT NULL DEFAULT 'open',
                position        integer      NOT NULL DEFAULT 0,
                completed_at    timestamptz  NULL,
                created_at      timestamptz  NOT NULL DEFAULT now(),
                updated_at      timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT ck_milestones_status
                    CHECK (status IN ('open','at_risk','completed','missed')),
                -- Status and timestamp must agree; a "completed" milestone with
                -- no completion time makes every cycle-time report wrong.
                CONSTRAINT ck_milestones_completed
                    CHECK ((status = 'completed') = (completed_at IS NOT NULL)),

                CONSTRAINT fk_milestones_project
                    FOREIGN KEY (organization_id, project_id)
                    REFERENCES projects (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_milestones_org_id ON milestones (organization_id, id);
            CREATE INDEX idx_milestones_project ON milestones (project_id, position);
            CREATE INDEX idx_milestones_due ON milestones (organization_id, due_date)
                WHERE status IN ('open','at_risk');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS milestones CASCADE;
            DROP TABLE IF EXISTS project_members CASCADE;
            DROP TABLE IF EXISTS projects CASCADE;
        SQL);
    }
};

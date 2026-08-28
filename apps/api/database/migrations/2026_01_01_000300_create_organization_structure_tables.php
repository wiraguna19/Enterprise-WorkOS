<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── departments ──────────────────────────────────────────────────────
        // Hierarchy via materialized path. "Everything under Engineering" is
        // then one index scan (`path LIKE '/eng/%'`) instead of a recursive CTE
        // on every dashboard load. Writes are rare, reads are constant
        // (docs/03 §2).
        DB::unprepared(<<<'SQL'
            CREATE TABLE departments (
                id                  uuid         PRIMARY KEY,
                organization_id     uuid         NOT NULL
                                    REFERENCES organizations (id) ON DELETE CASCADE,
                parent_id           uuid         NULL,
                name                varchar(120) NOT NULL,
                code                varchar(40)  NOT NULL,
                head_membership_id  uuid         NULL,
                path                varchar(512) NOT NULL,
                depth               smallint     NOT NULL DEFAULT 0,
                created_at          timestamptz  NOT NULL DEFAULT now(),
                updated_at          timestamptz  NOT NULL DEFAULT now(),
                archived_at         timestamptz  NULL,

                CONSTRAINT ck_departments_depth CHECK (depth >= 0 AND depth <= 6),
                CONSTRAINT ck_departments_not_self_parent CHECK (parent_id IS NULL OR parent_id <> id),

                CONSTRAINT fk_departments_head
                    FOREIGN KEY (organization_id, head_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE SET NULL
            );

            CREATE UNIQUE INDEX uq_departments_org_id   ON departments (organization_id, id);
            CREATE UNIQUE INDEX uq_departments_org_code ON departments (organization_id, code);
            CREATE INDEX idx_departments_path           ON departments (organization_id, path varchar_pattern_ops);
            CREATE INDEX idx_departments_parent         ON departments (parent_id);

            -- The self-referencing tenant-checked FK is added after the unique
            -- index it depends on exists. Declaring it inline fails: Postgres
            -- resolves the referenced key at CREATE TABLE time.
            ALTER TABLE departments
                ADD CONSTRAINT fk_departments_parent
                FOREIGN KEY (organization_id, parent_id)
                REFERENCES departments (organization_id, id) ON DELETE RESTRICT;
        SQL);

        // ── teams ────────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TABLE teams (
                id                  uuid         PRIMARY KEY,
                organization_id     uuid         NOT NULL
                                    REFERENCES organizations (id) ON DELETE CASCADE,
                department_id       uuid         NULL,
                name                varchar(120) NOT NULL,
                key                 varchar(40)  NOT NULL,
                description         varchar(500) NOT NULL DEFAULT '',
                lead_membership_id  uuid         NULL,
                created_at          timestamptz  NOT NULL DEFAULT now(),
                updated_at          timestamptz  NOT NULL DEFAULT now(),
                archived_at         timestamptz  NULL,

                CONSTRAINT fk_teams_department
                    FOREIGN KEY (organization_id, department_id)
                    REFERENCES departments (organization_id, id) ON DELETE SET NULL,
                CONSTRAINT fk_teams_lead
                    FOREIGN KEY (organization_id, lead_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE SET NULL
            );

            CREATE UNIQUE INDEX uq_teams_org_id  ON teams (organization_id, id);
            CREATE UNIQUE INDEX uq_teams_org_key ON teams (organization_id, key);
            CREATE INDEX idx_teams_department    ON teams (organization_id, department_id);
        SQL);

        // ── team members ─────────────────────────────────────────────────────
        // Rows are closed (left_at), never deleted: team composition history is
        // needed to interpret past workload and past work.
        DB::unprepared(<<<'SQL'
            CREATE TABLE team_members (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL
                                REFERENCES organizations (id) ON DELETE CASCADE,
                team_id         uuid         NOT NULL,
                membership_id   uuid         NOT NULL,
                role            varchar(20)  NOT NULL DEFAULT 'member',
                joined_at       timestamptz  NOT NULL DEFAULT now(),
                left_at         timestamptz  NULL,

                CONSTRAINT ck_team_members_role
                    CHECK (role IN ('member', 'lead')),
                CONSTRAINT fk_team_members_team
                    FOREIGN KEY (organization_id, team_id)
                    REFERENCES teams (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_team_members_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            -- One active membership per person per team; historical rows unrestricted.
            CREATE UNIQUE INDEX uq_team_members_active
                ON team_members (team_id, membership_id) WHERE left_at IS NULL;
            CREATE INDEX idx_team_members_membership
                ON team_members (organization_id, membership_id) WHERE left_at IS NULL;
        SQL);

        // ── employee profiles ────────────────────────────────────────────────
        // weekly_capacity_hours is what makes the workload calculation in
        // docs/02 §11 an actual number rather than a guess.
        DB::unprepared(<<<'SQL'
            CREATE TABLE employee_profiles (
                id                      uuid          PRIMARY KEY,
                organization_id         uuid          NOT NULL
                                        REFERENCES organizations (id) ON DELETE CASCADE,
                membership_id           uuid          NOT NULL,
                employee_number         varchar(40)   NULL,
                job_title               varchar(120)  NOT NULL DEFAULT '',
                department_id           uuid          NULL,
                manager_profile_id      uuid          NULL,
                employment_type         varchar(20)   NOT NULL DEFAULT 'full_time',
                weekly_capacity_hours   numeric(5,2)  NOT NULL DEFAULT 40.00,
                hired_at                date          NULL,
                work_location           varchar(120)  NOT NULL DEFAULT '',
                created_at              timestamptz   NOT NULL DEFAULT now(),
                updated_at              timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_employee_profiles_employment_type
                    CHECK (employment_type IN ('full_time', 'part_time', 'contract', 'intern')),
                CONSTRAINT ck_employee_profiles_capacity
                    CHECK (weekly_capacity_hours > 0 AND weekly_capacity_hours <= 168),
                CONSTRAINT ck_employee_profiles_not_own_manager
                    CHECK (manager_profile_id IS NULL OR manager_profile_id <> id),

                CONSTRAINT fk_employee_profiles_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_employee_profiles_department
                    FOREIGN KEY (organization_id, department_id)
                    REFERENCES departments (organization_id, id) ON DELETE SET NULL
            );

            CREATE UNIQUE INDEX uq_employee_profiles_org_id
                ON employee_profiles (organization_id, id);
            CREATE UNIQUE INDEX uq_employee_profiles_membership
                ON employee_profiles (membership_id);
            CREATE UNIQUE INDEX uq_employee_profiles_number
                ON employee_profiles (organization_id, employee_number)
                WHERE employee_number IS NOT NULL;
            CREATE INDEX idx_employee_profiles_manager
                ON employee_profiles (organization_id, manager_profile_id);
            CREATE INDEX idx_employee_profiles_department
                ON employee_profiles (organization_id, department_id);

            -- Reporting line, added after its unique index exists (see departments).
            ALTER TABLE employee_profiles
                ADD CONSTRAINT fk_employee_profiles_manager
                FOREIGN KEY (organization_id, manager_profile_id)
                REFERENCES employee_profiles (organization_id, id) ON DELETE SET NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS employee_profiles CASCADE;
            DROP TABLE IF EXISTS team_members CASCADE;
            DROP TABLE IF EXISTS teams CASCADE;
            DROP TABLE IF EXISTS departments CASCADE;
        SQL);
    }
};

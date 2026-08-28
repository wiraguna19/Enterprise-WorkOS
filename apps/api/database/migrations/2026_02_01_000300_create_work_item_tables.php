<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The core of the system (docs/02 §2, docs/03 §3).
 *
 * ONE typed table. A "task" is a work_item with type = 'task'; request,
 * incident, review, and campaign are siblings, not subclasses. Assignment,
 * comments, attachments, activity, tags, custom fields, dependencies,
 * hierarchy, and search are implemented once here and are therefore free for
 * every work type this product grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE work_items (
                id                      uuid          PRIMARY KEY,
                organization_id         uuid          NOT NULL
                                        REFERENCES organizations (id) ON DELETE CASCADE,

                -- The polymorphism. CHECK-constrained rather than a PG ENUM:
                -- adding a value to an enum type inside a transaction is
                -- painful; a CHECK is a one-line migration (docs/03 §0).
                type                    varchar(20)   NOT NULL DEFAULT 'task',

                -- Human-readable identity. People say "ENG-142" in chat; a UUID
                -- is unusable for that, and the URL is /work/ENG-142.
                reference               varchar(24)   NOT NULL,

                title                   varchar(500)  NOT NULL,
                description             text          NOT NULL DEFAULT '',

                -- Nullable, and that is a FEATURE. An IT request, a personal
                -- task, an incident, or a compliance review is real work that
                -- must appear in workload and dashboards. Any query that
                -- assumes project_id IS NOT NULL is a bug (docs/02 §5).
                project_id              uuid          NULL,
                parent_id               uuid          NULL,
                milestone_id            uuid          NULL,
                created_by_membership_id uuid         NULL,

                workflow_id             uuid          NOT NULL,
                workflow_state_id       uuid          NOT NULL,

                -- DENORMALISED from workflow_states.category, on purpose.
                -- Every list, count, dashboard, and overdue check filters on
                -- category; joining workflow_states for all of them is a
                -- measurable cost. Written only inside the same transaction as
                -- the state change, and a consistency test asserts no row
                -- disagrees (docs/03 §3, docs/12 §5).
                state_category          varchar(20)   NOT NULL,

                priority                varchar(10)   NOT NULL DEFAULT 'medium',

                -- start_date is a DATE, due_at is a TIMESTAMPTZ. Deadlines have
                -- a time ("Friday 17:00"); start dates in practice do not.
                -- Mixing the two types is a reliable source of off-by-one-day
                -- timezone bugs (docs/03 §3).
                start_date              date          NULL,
                due_at                  timestamptz   NULL,

                estimate_hours          numeric(7,2)  NULL,
                -- Derived from time_entries by a queued job; never authoritative.
                actual_hours_cache      numeric(9,2)  NOT NULL DEFAULT 0,

                -- Fractional ordering: moving one card between two others
                -- writes ONE row instead of renumbering a whole column.
                position                numeric(20,10) NOT NULL DEFAULT 1000,

                recurrence_id           uuid          NULL,
                lock_version            integer       NOT NULL DEFAULT 0,

                -- A generated column rather than a trigger: to_tsvector with an
                -- explicit configuration is immutable, so Postgres maintains it
                -- itself and it can never drift from title/description.
                search_vector           tsvector      GENERATED ALWAYS AS (
                                            setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                                            setweight(to_tsvector('english', coalesce(description, '')), 'B')
                                        ) STORED,

                completed_at            timestamptz   NULL,
                created_at              timestamptz   NOT NULL DEFAULT now(),
                updated_at              timestamptz   NOT NULL DEFAULT now(),
                deleted_at              timestamptz   NULL,

                CONSTRAINT ck_work_items_type
                    CHECK (type IN (
                        'task','request','approval_work','incident',
                        'review','campaign','operational'
                    )),
                CONSTRAINT ck_work_items_state_category
                    CHECK (state_category IN (
                        'backlog','todo','in_progress','in_review',
                        'blocked','done','cancelled'
                    )),
                CONSTRAINT ck_work_items_priority
                    CHECK (priority IN ('low','medium','high','urgent')),
                CONSTRAINT ck_work_items_dates
                    CHECK (start_date IS NULL OR due_at IS NULL OR start_date <= due_at::date),
                CONSTRAINT ck_work_items_estimate
                    CHECK (estimate_hours IS NULL OR estimate_hours >= 0),
                CONSTRAINT ck_work_items_not_own_parent
                    CHECK (parent_id IS NULL OR parent_id <> id),
                -- A terminal state and a completion timestamp must agree, or
                -- every cycle-time metric silently lies.
                CONSTRAINT ck_work_items_completed
                    CHECK ((state_category = 'done') = (completed_at IS NOT NULL)),
                CONSTRAINT ck_work_items_title_not_blank
                    CHECK (length(btrim(title)) > 0),

                CONSTRAINT fk_work_items_project
                    FOREIGN KEY (organization_id, project_id)
                    REFERENCES projects (organization_id, id) ON DELETE SET NULL,
                CONSTRAINT fk_work_items_milestone
                    FOREIGN KEY (organization_id, milestone_id)
                    REFERENCES milestones (organization_id, id) ON DELETE SET NULL,
                CONSTRAINT fk_work_items_workflow
                    FOREIGN KEY (organization_id, workflow_id)
                    REFERENCES workflows (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_work_items_state
                    FOREIGN KEY (organization_id, workflow_state_id)
                    REFERENCES workflow_states (organization_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_work_items_creator
                    FOREIGN KEY (organization_id, created_by_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE SET NULL
            );

            CREATE UNIQUE INDEX uq_work_items_org_id ON work_items (organization_id, id);

            -- Self-referencing tenant-checked FK, added after its unique index
            -- exists (Postgres resolves the referenced key at CREATE TABLE).
            ALTER TABLE work_items
                ADD CONSTRAINT fk_work_items_parent
                FOREIGN KEY (organization_id, parent_id)
                REFERENCES work_items (organization_id, id) ON DELETE RESTRICT;
        SQL);

        // ── indexes: the table that decides whether the app is fast ─────────
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX uq_work_items_reference
                ON work_items (organization_id, reference);

            -- The single most important index: "my work", scoped and sorted.
            CREATE INDEX idx_wi_org_state_due
                ON work_items (organization_id, state_category, due_at)
                WHERE deleted_at IS NULL;

            -- Board and list rendering, in board order.
            CREATE INDEX idx_wi_org_project_position
                ON work_items (organization_id, project_id, position)
                WHERE deleted_at IS NULL;

            CREATE INDEX idx_wi_parent    ON work_items (parent_id) WHERE deleted_at IS NULL;
            CREATE INDEX idx_wi_milestone ON work_items (milestone_id);
            CREATE INDEX idx_wi_type      ON work_items (organization_id, type);
            CREATE INDEX idx_wi_search    ON work_items USING GIN (search_vector);

            -- Overdue detection scans this constantly; a partial index keeps it
            -- to the rows that can actually BE overdue.
            CREATE INDEX idx_wi_overdue
                ON work_items (organization_id, due_at)
                WHERE deleted_at IS NULL
                  AND state_category NOT IN ('done','cancelled')
                  AND due_at IS NOT NULL;
        SQL);

        // ── assignment: an entity, not a pivot ──────────────────────────────
        // A task_assignees pivot answers "who is on this now" and destroys the
        // answer to every interesting question: who was on it before, who
        // reassigned it, how long the handover took, how many times it bounced.
        // Rows are CLOSED, never deleted (docs/02 §6).
        DB::unprepared(<<<'SQL'
            CREATE TABLE work_item_assignments (
                id                       uuid         PRIMARY KEY,
                organization_id          uuid         NOT NULL,
                work_item_id             uuid         NOT NULL,
                membership_id            uuid         NOT NULL,
                role                     varchar(20)  NOT NULL DEFAULT 'assignee',
                is_primary               boolean      NOT NULL DEFAULT true,
                assigned_by_membership_id uuid        NULL,
                assigned_at              timestamptz  NOT NULL DEFAULT now(),
                -- Did they acknowledge? An assignment nobody accepted is a
                -- different situation from one in progress, and managers need
                -- to see the difference.
                accepted_at              timestamptz  NULL,
                unassigned_at            timestamptz  NULL,
                unassigned_reason        varchar(200) NULL,

                CONSTRAINT ck_wia_role
                    CHECK (role IN ('assignee','reviewer','approver','watcher','collaborator')),
                CONSTRAINT ck_wia_closed_has_reason
                    CHECK (unassigned_at IS NOT NULL OR unassigned_reason IS NULL),

                CONSTRAINT fk_wia_work_item
                    FOREIGN KEY (organization_id, work_item_id)
                    REFERENCES work_items (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_wia_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE RESTRICT
            );

            -- At most one ACTIVE primary assignee per item. Historical rows are
            -- unrestricted, which is what makes the reassignment narrative
            -- possible.
            CREATE UNIQUE INDEX uq_wia_one_active_assignee
                ON work_item_assignments (work_item_id)
                WHERE unassigned_at IS NULL AND role = 'assignee' AND is_primary;

            -- No duplicate active row for the same person in the same role.
            CREATE UNIQUE INDEX uq_wia_active_person_role
                ON work_item_assignments (work_item_id, membership_id, role)
                WHERE unassigned_at IS NULL;

            -- "What is currently on my plate" — the hottest join in the product.
            CREATE INDEX idx_wia_active
                ON work_item_assignments (organization_id, membership_id, role)
                WHERE unassigned_at IS NULL;

            -- Assignment history for one item, newest first.
            CREATE INDEX idx_wia_history
                ON work_item_assignments (work_item_id, assigned_at DESC);
        SQL);

        // ── dependencies ────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TABLE work_item_dependencies (
                id                      uuid         PRIMARY KEY,
                organization_id         uuid         NOT NULL,
                work_item_id            uuid         NOT NULL,
                depends_on_work_item_id uuid         NOT NULL,
                type                    varchar(20)  NOT NULL DEFAULT 'blocks',
                created_by              uuid         NULL,
                created_at              timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT ck_wid_type
                    CHECK (type IN ('blocks','relates_to','duplicates')),
                CONSTRAINT ck_wid_not_self
                    CHECK (work_item_id <> depends_on_work_item_id),

                CONSTRAINT fk_wid_item
                    FOREIGN KEY (organization_id, work_item_id)
                    REFERENCES work_items (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_wid_depends_on
                    FOREIGN KEY (organization_id, depends_on_work_item_id)
                    REFERENCES work_items (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_wid_pair
                ON work_item_dependencies (work_item_id, depends_on_work_item_id, type);
            CREATE INDEX idx_wid_reverse
                ON work_item_dependencies (depends_on_work_item_id);
        SQL);

        // Longer cycles cannot be expressed as a constraint — Postgres has no
        // "acyclic" declaration — so the application runs a recursive CTE
        // before insert (see DependencyService). The CHECK above catches only
        // the trivial self-edge.

        // ── watchers and time entries ───────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TABLE work_item_watchers (
                id              uuid        PRIMARY KEY,
                organization_id uuid        NOT NULL,
                work_item_id    uuid        NOT NULL,
                membership_id   uuid        NOT NULL,
                created_at      timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT fk_wiw_item
                    FOREIGN KEY (organization_id, work_item_id)
                    REFERENCES work_items (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_wiw_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_wiw_pair ON work_item_watchers (work_item_id, membership_id);
            CREATE INDEX idx_wiw_membership ON work_item_watchers (organization_id, membership_id);

            CREATE TABLE time_entries (
                id              uuid          PRIMARY KEY,
                organization_id uuid          NOT NULL,
                work_item_id    uuid          NOT NULL,
                membership_id   uuid          NOT NULL,
                hours           numeric(6,2)  NOT NULL,
                logged_on       date          NOT NULL,
                note            varchar(300)  NOT NULL DEFAULT '',
                created_at      timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_time_entries_hours
                    CHECK (hours > 0 AND hours <= 24),

                CONSTRAINT fk_time_entries_item
                    FOREIGN KEY (organization_id, work_item_id)
                    REFERENCES work_items (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_time_entries_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE RESTRICT
            );

            CREATE INDEX idx_time_entries_item ON time_entries (work_item_id);
            CREATE INDEX idx_time_entries_person
                ON time_entries (organization_id, membership_id, logged_on);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS time_entries CASCADE;
            DROP TABLE IF EXISTS work_item_watchers CASCADE;
            DROP TABLE IF EXISTS work_item_dependencies CASCADE;
            DROP TABLE IF EXISTS work_item_assignments CASCADE;
            DROP TABLE IF EXISTS work_items CASCADE;
        SQL);
    }
};

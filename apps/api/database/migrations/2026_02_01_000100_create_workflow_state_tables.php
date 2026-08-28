<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Workflow STATES, brought forward from Phase 4.
 *
 * Deviation from docs/10, stated rather than made silently: the roadmap places
 * states in Phase 4 alongside transitions, guards, versioning, and the rule
 * engine. But a work item cannot exist without a status, and a Phase 3 that
 * hardcodes a status enum would have to be unpicked in Phase 4 — exactly the
 * coupling docs/02 §7 exists to prevent.
 *
 * So Phase 3 ships the smallest part that work items genuinely require:
 * workflows and their states, as data. Transitions, guards, rules, versioning,
 * and the builder UI remain Phase 4. Nothing here presumes their shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE workflows (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL
                                REFERENCES organizations (id) ON DELETE CASCADE,
                name            varchar(120) NOT NULL,
                applies_to_type varchar(30)  NOT NULL DEFAULT 'task',
                version         integer      NOT NULL DEFAULT 1,
                is_default      boolean      NOT NULL DEFAULT false,
                is_active       boolean      NOT NULL DEFAULT true,
                -- Editing a live workflow creates a new version rather than
                -- mutating history; existing work keeps its version until
                -- explicitly migrated (docs/02 §4.4). Phase 4 populates this.
                superseded_by_id uuid        NULL,
                created_at      timestamptz  NOT NULL DEFAULT now(),
                updated_at      timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT ck_workflows_applies_to
                    CHECK (applies_to_type IN (
                        'task','request','approval_work','incident',
                        'review','campaign','operational'
                    ))
            );

            CREATE UNIQUE INDEX uq_workflows_org_id ON workflows (organization_id, id);
            CREATE UNIQUE INDEX uq_workflows_default
                ON workflows (organization_id, applies_to_type)
                WHERE is_default AND is_active;

            ALTER TABLE workflows
                ADD CONSTRAINT fk_workflows_superseded_by
                FOREIGN KEY (organization_id, superseded_by_id)
                REFERENCES workflows (organization_id, id) ON DELETE SET NULL;
        SQL);

        // ── workflow_states ─────────────────────────────────────────────────
        // `category` is the piece that makes a generic UI possible. Every
        // customer-defined state maps to one of seven fixed buckets; the UI,
        // reports, and "is this overdue?" logic key off the CATEGORY, never the
        // label. That is how "In Review" can be renamed "QA Gate" without
        // breaking a single dashboard (docs/02 §7).
        DB::unprepared(<<<'SQL'
            CREATE TABLE workflow_states (
                id                 uuid        PRIMARY KEY,
                organization_id    uuid        NOT NULL,
                workflow_id        uuid        NOT NULL,
                key                varchar(40) NOT NULL,
                label              varchar(60) NOT NULL,
                category           varchar(20) NOT NULL,
                color              varchar(20) NOT NULL DEFAULT 'neutral',
                position           smallint    NOT NULL DEFAULT 0,
                is_initial         boolean     NOT NULL DEFAULT false,
                is_terminal        boolean     NOT NULL DEFAULT false,
                requires_approval  boolean     NOT NULL DEFAULT false,
                created_at         timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT ck_workflow_states_category
                    CHECK (category IN (
                        'backlog','todo','in_progress','in_review',
                        'blocked','done','cancelled'
                    )),
                CONSTRAINT fk_workflow_states_workflow
                    FOREIGN KEY (organization_id, workflow_id)
                    REFERENCES workflows (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_workflow_states_org_id
                ON workflow_states (organization_id, id);
            CREATE UNIQUE INDEX uq_workflow_states_key
                ON workflow_states (workflow_id, key);

            -- Exactly one initial state per workflow: without this, creating a
            -- work item has no deterministic starting status.
            CREATE UNIQUE INDEX uq_workflow_states_one_initial
                ON workflow_states (workflow_id) WHERE is_initial;

            CREATE INDEX idx_workflow_states_workflow
                ON workflow_states (workflow_id, position);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS workflow_states CASCADE;
            DROP TABLE IF EXISTS workflows CASCADE;
        SQL);
    }
};

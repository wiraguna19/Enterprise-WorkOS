<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The workflow engine (docs/02 §7).
 *
 * Phase 3 shipped states as data. This adds the parts that make them a
 * machine rather than a list: which moves are legal, what guards them, what
 * happens automatically, and a permanent record of every move made.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── transitions: which moves are legal ──────────────────────────────
        // A transition is a ROW, not an if-statement. That is the whole point:
        // a customer can add "Blocked → Cancelled" without a deployment, and
        // the set of legal moves is queryable — which is what lets the UI show
        // only the buttons that will actually work.
        DB::unprepared(<<<'SQL'
            CREATE TABLE workflow_transitions (
                id                  uuid         PRIMARY KEY,
                organization_id     uuid         NOT NULL,
                workflow_id         uuid         NOT NULL,
                from_state_id       uuid         NULL,
                to_state_id         uuid         NOT NULL,
                label               varchar(60)  NOT NULL,

                -- A JSON predicate tree evaluated against the actor and the
                -- subject. Kept as data rather than a class name so a guard can
                -- be authored in the builder UI later without shipping code.
                guard               jsonb        NOT NULL DEFAULT '{}'::jsonb,

                -- Some moves must be explained. "Request changes" without a
                -- reason just sends the work round the loop again (docs/08 §4).
                requires_comment    boolean      NOT NULL DEFAULT false,
                position            smallint     NOT NULL DEFAULT 0,
                created_at          timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT ck_wt_not_self
                    CHECK (from_state_id IS NULL OR from_state_id <> to_state_id),

                CONSTRAINT fk_wt_workflow
                    FOREIGN KEY (organization_id, workflow_id)
                    REFERENCES workflows (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_wt_from
                    FOREIGN KEY (organization_id, from_state_id)
                    REFERENCES workflow_states (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_wt_to
                    FOREIGN KEY (organization_id, to_state_id)
                    REFERENCES workflow_states (organization_id, id) ON DELETE CASCADE
            );

            -- from_state_id NULL means "from anywhere" — how Cancelled and
            -- Blocked are reachable without enumerating every source state.
            CREATE UNIQUE INDEX uq_wt_pair
                ON workflow_transitions
                   (workflow_id, COALESCE(from_state_id, '00000000-0000-0000-0000-000000000000'::uuid), to_state_id);

            -- "What can I do from here" runs on every work item render.
            CREATE INDEX idx_wt_from
                ON workflow_transitions (workflow_id, from_state_id, position);
        SQL);

        // ── transition history ──────────────────────────────────────────────
        // Separate from activity_logs because this is STRUCTURED data that
        // cycle-time analytics query directly, not narrative text (docs/03 §4).
        DB::unprepared(<<<'SQL'
            CREATE TABLE work_item_transitions (
                id                  uuid         NOT NULL,
                organization_id     uuid         NOT NULL,
                work_item_id        uuid         NOT NULL,
                from_state_id       uuid         NULL,
                to_state_id         uuid         NOT NULL,
                from_category       varchar(20)  NULL,
                to_category         varchar(20)  NOT NULL,
                actor_membership_id uuid         NULL,

                -- Who or what caused this: a person, a rule, a recurrence, or
                -- an escalation. Without it, automated changes are indis-
                -- tinguishable from human ones and impossible to debug.
                cause               varchar(20)  NOT NULL DEFAULT 'user',

                -- Links a change to the rule execution that produced it, and
                -- chains rule-caused changes together. This is what the
                -- recursion guard counts (docs/02 §7).
                causation_id        uuid         NULL,
                causation_depth     smallint     NOT NULL DEFAULT 0,

                override_reason     varchar(200) NULL,
                occurred_at         timestamptz  NOT NULL DEFAULT now(),

                PRIMARY KEY (id, occurred_at),

                CONSTRAINT ck_wit_cause
                    CHECK (cause IN ('user','rule','recurrence','escalation','system')),
                CONSTRAINT ck_wit_depth
                    CHECK (causation_depth >= 0 AND causation_depth <= 10)
            ) PARTITION BY RANGE (occurred_at);

            CREATE INDEX idx_wit_item
                ON work_item_transitions (organization_id, work_item_id, occurred_at DESC);
            CREATE INDEX idx_wit_causation
                ON work_item_transitions (causation_id) WHERE causation_id IS NOT NULL;

            -- Cycle time is computed from this table, so the analytics query
            -- gets its own index rather than sharing the timeline's.
            CREATE INDEX idx_wit_category_flow
                ON work_item_transitions (organization_id, to_category, occurred_at);
        SQL);

        // ── rules: trigger → condition → action ─────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TABLE workflow_rules (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL,
                workflow_id     uuid         NULL,
                name            varchar(120) NOT NULL,
                description     varchar(500) NOT NULL DEFAULT '',

                trigger         varchar(40)  NOT NULL,
                conditions      jsonb        NOT NULL DEFAULT '{}'::jsonb,
                actions         jsonb        NOT NULL DEFAULT '[]'::jsonb,

                is_active       boolean      NOT NULL DEFAULT true,
                run_order       smallint     NOT NULL DEFAULT 0,

                -- A rule that keeps failing must stop rather than retry
                -- forever: a stuck rule silently degrades every workflow it
                -- touches, and a disabled one is visible.
                failure_count   integer      NOT NULL DEFAULT 0,
                disabled_reason varchar(200) NULL,

                created_at      timestamptz  NOT NULL DEFAULT now(),
                updated_at      timestamptz  NOT NULL DEFAULT now(),

                -- The closed trigger set. Phase 4 ships six; a seventh is a
                -- migration plus a handler, which is deliberate friction —
                -- an open-ended trigger vocabulary is how a workflow engine
                -- becomes a no-code platform nobody asked for (docs/12 §10).
                CONSTRAINT ck_wr_trigger
                    CHECK (trigger IN (
                        'work_item.status_changed',
                        'work_item.assigned',
                        'work_item.created',
                        'approval.decided',
                        'schedule.due_soon',
                        'schedule.overdue'
                    )),

                CONSTRAINT fk_wr_workflow
                    FOREIGN KEY (organization_id, workflow_id)
                    REFERENCES workflows (organization_id, id) ON DELETE CASCADE
            );

            CREATE INDEX idx_wr_trigger
                ON workflow_rules (organization_id, trigger, run_order)
                WHERE is_active;
        SQL);

        // ── rule run log ────────────────────────────────────────────────────
        // Every evaluation is recorded, including the ones that matched
        // nothing. "Why didn't my rule fire?" is the single most common
        // question about any automation system, and it is unanswerable without
        // this table.
        DB::unprepared(<<<'SQL'
            CREATE TABLE workflow_rule_runs (
                id                  uuid         NOT NULL,
                organization_id     uuid         NOT NULL,
                rule_id             uuid         NOT NULL,
                subject_type        varchar(40)  NOT NULL,
                subject_id          uuid         NOT NULL,
                causation_id        uuid         NULL,
                causation_depth     smallint     NOT NULL DEFAULT 0,
                outcome             varchar(20)  NOT NULL,
                matched             boolean      NOT NULL DEFAULT false,
                actions_run         jsonb        NOT NULL DEFAULT '[]'::jsonb,
                error               text         NULL,
                duration_ms         integer      NULL,
                occurred_at         timestamptz  NOT NULL DEFAULT now(),

                PRIMARY KEY (id, occurred_at),

                CONSTRAINT ck_wrr_outcome
                    CHECK (outcome IN ('applied','skipped','failed','depth_exceeded'))
            ) PARTITION BY RANGE (occurred_at);

            CREATE INDEX idx_wrr_rule
                ON workflow_rule_runs (organization_id, rule_id, occurred_at DESC);
            CREATE INDEX idx_wrr_subject
                ON workflow_rule_runs (subject_type, subject_id, occurred_at DESC);
        SQL);

        $this->createPartitions();
    }

    /**
     * Both new log tables are partitioned by month from the first migration,
     * for the same reason as activity and audit: retrofitting partitioning onto
     * a large table is a maintenance window nobody wants (docs/03 §6).
     */
    private function createPartitions(): void
    {
        $start = new DateTimeImmutable('first day of this month 00:00:00');

        foreach (['work_item_transitions', 'workflow_rule_runs'] as $table) {
            for ($i = -1; $i < 12; $i++) {
                $from = $start->modify("{$i} months");
                $to = $from->modify('+1 month');

                DB::unprepared(sprintf(
                    'CREATE TABLE %1$s_p%2$s PARTITION OF %1$s FOR VALUES FROM (%3$s) TO (%4$s);',
                    $table,
                    $from->format('Y_m'),
                    "'".$from->format('Y-m-d')."'",
                    "'".$to->format('Y-m-d')."'",
                ));
            }

            DB::unprepared("CREATE TABLE {$table}_default PARTITION OF {$table} DEFAULT;");
        }

        // Transition history is append-only for the same reason audit is: it is
        // the evidence, and evidence the application can rewrite is not
        // evidence. Cycle-time reports are computed from it.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_work_item_transitions_append_only
                BEFORE UPDATE OR DELETE ON work_item_transitions
                FOR EACH ROW EXECUTE FUNCTION refuse_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS workflow_rule_runs CASCADE;
            DROP TABLE IF EXISTS work_item_transitions CASCADE;
            DROP TABLE IF EXISTS workflow_rules CASCADE;
            DROP TABLE IF EXISTS workflow_transitions CASCADE;
        SQL);
    }
};

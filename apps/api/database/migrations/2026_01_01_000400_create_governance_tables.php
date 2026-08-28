<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── activity_logs ────────────────────────────────────────────────────
        // User-facing narrative, per subject. Partitioned by month from the very
        // first migration: retrofitting partitioning onto a 200M-row table is a
        // maintenance window nobody wants (docs/03 §6).
        //
        // The partition key must be part of the primary key — hence (id, occurred_at).
        DB::unprepared(<<<'SQL'
            CREATE TABLE activity_logs (
                id                  uuid         NOT NULL,
                organization_id     uuid         NOT NULL,
                subject_type        varchar(60)  NOT NULL,
                subject_id          uuid         NOT NULL,
                actor_membership_id uuid         NULL,
                actor_name_snapshot varchar(160) NOT NULL DEFAULT 'System',
                verb                varchar(60)  NOT NULL,
                changes             jsonb        NOT NULL DEFAULT '{}'::jsonb,
                correlation_id      uuid         NULL,
                occurred_at         timestamptz  NOT NULL DEFAULT now(),

                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at);

            CREATE INDEX idx_activity_subject
                ON activity_logs (organization_id, subject_type, subject_id, occurred_at DESC);
            CREATE INDEX idx_activity_actor
                ON activity_logs (organization_id, actor_membership_id, occurred_at DESC);
            CREATE INDEX idx_activity_correlation
                ON activity_logs (correlation_id) WHERE correlation_id IS NOT NULL;
        SQL);

        // ── audit_logs ───────────────────────────────────────────────────────
        // Security and compliance. Deliberately NOT the same table as activity:
        // different audience, different retention, and it must survive the
        // deletion of whatever it describes (docs/02 §8).
        DB::unprepared(<<<'SQL'
            CREATE TABLE audit_logs (
                id                   uuid         NOT NULL,
                organization_id      uuid         NULL,
                actor_user_id        uuid         NULL,
                actor_email_snapshot varchar(255) NOT NULL DEFAULT '',
                event                varchar(80)  NOT NULL,
                target_type          varchar(60)  NULL,
                target_id            uuid         NULL,
                metadata             jsonb        NOT NULL DEFAULT '{}'::jsonb,
                ip_address           inet         NULL,
                user_agent           text         NULL,
                occurred_at          timestamptz  NOT NULL DEFAULT now(),

                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at);

            CREATE INDEX idx_audit_org_event
                ON audit_logs (organization_id, event, occurred_at DESC);
            CREATE INDEX idx_audit_actor
                ON audit_logs (actor_user_id, occurred_at DESC);
            CREATE INDEX idx_audit_target
                ON audit_logs (target_type, target_id, occurred_at DESC);
        SQL);

        $this->createPartitions();
        $this->enforceAppendOnly();

        // ── settings ─────────────────────────────────────────────────────────
        // Hierarchical: organization → department → project → user. The resolver
        // takes the most specific value present (docs/03 §6).
        DB::unprepared(<<<'SQL'
            CREATE TABLE settings (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL
                                REFERENCES organizations (id) ON DELETE CASCADE,
                scope_type      varchar(20)  NOT NULL DEFAULT 'organization',
                scope_id        uuid         NULL,
                key             varchar(120) NOT NULL,
                value           jsonb        NOT NULL,
                created_at      timestamptz  NOT NULL DEFAULT now(),
                updated_at      timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT ck_settings_scope_type
                    CHECK (scope_type IN ('organization', 'department', 'project', 'membership')),
                CONSTRAINT ck_settings_scope_id_present
                    CHECK ((scope_type = 'organization') = (scope_id IS NULL))
            );

            CREATE UNIQUE INDEX uq_settings_scope_key
                ON settings (organization_id, scope_type, COALESCE(scope_id, '00000000-0000-0000-0000-000000000000'::uuid), key);
        SQL);
    }

    /**
     * Twelve months ahead plus a DEFAULT partition.
     *
     * The default partition is a safety net, not a strategy: a scheduled command
     * creates next month's partition, and an alert fires if the default ever
     * receives rows (it means partition creation stopped running).
     */
    private function createPartitions(): void
    {
        $start = new DateTimeImmutable('first day of this month 00:00:00');

        foreach (['activity_logs', 'audit_logs'] as $table) {
            for ($i = -1; $i < 12; $i++) {
                $from = $start->modify("{$i} months");
                $to = $from->modify('+1 month');
                $suffix = $from->format('Y_m');

                DB::unprepared(sprintf(
                    'CREATE TABLE %1$s_p%2$s PARTITION OF %1$s FOR VALUES FROM (%3$s) TO (%4$s);',
                    $table,
                    $suffix,
                    "'".$from->format('Y-m-d')."'",
                    "'".$to->format('Y-m-d')."'",
                ));
            }

            DB::unprepared("CREATE TABLE {$table}_default PARTITION OF {$table} DEFAULT;");
        }
    }

    /**
     * Append-only enforced by the database, not by application discipline.
     *
     * The whole point of an audit trail is that the application cannot be
     * trusted to leave it alone (docs/03 §8 rule 5).
     */
    private function enforceAppendOnly(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'Table % is append-only; % is not permitted.',
                    TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_activity_logs_append_only
                BEFORE UPDATE OR DELETE ON activity_logs
                FOR EACH ROW EXECUTE FUNCTION refuse_mutation();

            CREATE TRIGGER trg_audit_logs_append_only
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION refuse_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS settings CASCADE;
            DROP TABLE IF EXISTS activity_logs CASCADE;
            DROP TABLE IF EXISTS audit_logs CASCADE;
            DROP FUNCTION IF EXISTS refuse_mutation() CASCADE;
        SQL);
    }
};

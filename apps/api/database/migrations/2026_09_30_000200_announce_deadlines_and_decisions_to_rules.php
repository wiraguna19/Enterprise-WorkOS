<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The rule triggers that were accepted and never fired (ADR 0057).
 *
 * `work_item_deadline_signals` remembers which deadline has already been
 * announced, so the scan that runs every fifteen minutes announces each one
 * ONCE. Keyed by the due date as well as the item and the signal: moving the
 * deadline is a new deadline, and it is announced again when it comes.
 *
 * The webhook catalogue grows to match — the three events are emitted now, so
 * an endpoint may subscribe to them (ADR 0048).
 */
return new class extends Migration
{
    private const PREDICATE = "organization_id = NULLIF(current_setting('app.organization_id', true), '')::uuid";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS work_item_deadline_signals (
                organization_id uuid         NOT NULL,
                work_item_id    uuid         NOT NULL,
                signal          varchar(20)  NOT NULL,
                due_at          timestamptz  NOT NULL,
                fired_at        timestamptz  NOT NULL DEFAULT now(),

                PRIMARY KEY (work_item_id, signal, due_at),

                CONSTRAINT ck_wids_signal CHECK (signal IN ('due_soon', 'overdue')),
                CONSTRAINT fk_wids_item
                    FOREIGN KEY (organization_id, work_item_id)
                    REFERENCES work_items (organization_id, id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_wids_org
                ON work_item_deadline_signals (organization_id, fired_at);

            ALTER TABLE webhook_endpoints DROP CONSTRAINT IF EXISTS ck_whe_events;
            ALTER TABLE webhook_endpoints ADD CONSTRAINT ck_whe_events CHECK (
                jsonb_typeof(events) = 'array'
                AND events <@ '["work_item.created", "work_item.assigned", "work_item.status_changed",
                                "approval.decided", "schedule.due_soon", "schedule.overdue"]'::jsonb
            );
        SQL);

        DB::unprepared(sprintf(
            'ALTER TABLE work_item_deadline_signals ENABLE ROW LEVEL SECURITY;'
            .' DROP POLICY IF EXISTS tenant_isolation ON work_item_deadline_signals;'
            .' CREATE POLICY tenant_isolation ON work_item_deadline_signals TO workos_tenant USING (%1$s) WITH CHECK (%1$s);',
            self::PREDICATE,
        ));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE webhook_endpoints DROP CONSTRAINT IF EXISTS ck_whe_events;
            ALTER TABLE webhook_endpoints ADD CONSTRAINT ck_whe_events CHECK (
                jsonb_typeof(events) = 'array'
                AND events <@ '["work_item.created", "work_item.assigned", "work_item.status_changed"]'::jsonb
            );
            DROP TABLE IF EXISTS work_item_deadline_signals CASCADE;
        SQL);
    }
};

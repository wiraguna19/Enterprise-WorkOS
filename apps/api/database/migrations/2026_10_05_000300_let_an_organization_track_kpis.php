<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Key performance indicators (ADR 0062): a number, a target, a period, about
 * one subject.
 *
 * A computed KPI stores no values — they are recomputed from the work data for
 * each period shown, so there is never a second copy of a number that already
 * has one. Only `manual` KPIs have rows in `kpi_entries`.
 *
 * The subject is one column pair, like an announcement's audience, and for the
 * same reason: one subject per KPI, and a subject that is archived later keeps
 * its history.
 */
return new class extends Migration
{
    private const PREDICATE = "organization_id = NULLIF(current_setting('app.organization_id', true), '')::uuid";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE kpis (
                id                          uuid           PRIMARY KEY,
                organization_id             uuid           NOT NULL
                                            REFERENCES organizations (id) ON DELETE CASCADE,
                name                        varchar(120)   NOT NULL,
                description                 varchar(1000)  NOT NULL DEFAULT '',
                subject_type                varchar(20)    NOT NULL,
                subject_id                  uuid           NOT NULL,
                source                      varchar(30)    NOT NULL,
                unit                        varchar(20)    NOT NULL DEFAULT '',
                direction                   varchar(10)    NOT NULL,
                target                      numeric(14,2)  NOT NULL,
                period                      varchar(10)    NOT NULL,
                created_by_membership_id    uuid           NOT NULL,
                created_at                  timestamptz    NOT NULL DEFAULT now(),
                updated_at                  timestamptz    NOT NULL DEFAULT now(),
                archived_at                 timestamptz    NULL,

                CONSTRAINT ck_kpis_subject_type
                    CHECK (subject_type IN ('team', 'department', 'project', 'person')),
                CONSTRAINT ck_kpis_source
                    CHECK (source IN ('manual', 'throughput', 'cycle_time_p85', 'on_time_rate')),
                CONSTRAINT ck_kpis_direction
                    CHECK (direction IN ('higher', 'lower')),
                CONSTRAINT ck_kpis_period
                    CHECK (period IN ('week', 'month', 'quarter')),
                CONSTRAINT ck_kpis_name
                    CHECK (length(btrim(name)) >= 1),

                CONSTRAINT fk_kpis_created_by
                    FOREIGN KEY (organization_id, created_by_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_kpis_org_id ON kpis (organization_id, id);
            CREATE INDEX idx_kpis_subject ON kpis (organization_id, subject_type, subject_id);

            CREATE TABLE kpi_entries (
                id                          uuid           PRIMARY KEY,
                organization_id             uuid           NOT NULL
                                            REFERENCES organizations (id) ON DELETE CASCADE,
                kpi_id                      uuid           NOT NULL,
                period_start                date           NOT NULL,
                value                       numeric(14,2)  NOT NULL,
                note                        varchar(500)   NOT NULL DEFAULT '',
                entered_by_membership_id    uuid           NOT NULL,
                entered_at                  timestamptz    NOT NULL DEFAULT now(),

                CONSTRAINT fk_kpi_entries_kpi
                    FOREIGN KEY (organization_id, kpi_id)
                    REFERENCES kpis (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_kpi_entries_entered_by
                    FOREIGN KEY (organization_id, entered_by_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            -- One value per period; recording it again corrects it.
            CREATE UNIQUE INDEX uq_kpi_entries_period ON kpi_entries (kpi_id, period_start);
        SQL);

        foreach (['kpis', 'kpi_entries'] as $table) {
            DB::unprepared(sprintf(
                'ALTER TABLE %1$s ENABLE ROW LEVEL SECURITY;'
                .' DROP POLICY IF EXISTS tenant_isolation ON %1$s;'
                .' CREATE POLICY tenant_isolation ON %1$s TO workos_tenant USING (%2$s) WITH CHECK (%2$s);',
                $table,
                self::PREDICATE,
            ));
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS kpi_entries CASCADE; DROP TABLE IF EXISTS kpis CASCADE;');
    }
};

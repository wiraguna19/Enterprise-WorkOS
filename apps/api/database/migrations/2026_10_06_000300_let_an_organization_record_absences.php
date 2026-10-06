<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Leave: the rules an organization sets, and the days it is closed (ADR 0063).
 *
 * Every company that uses this product has its own leave rules, so none of
 * them is code. Three tables of configuration, all the organization's own:
 *
 * - `leave_policies` — one row per organization: the period a quota runs over,
 *   how it is granted, probation, carry-over, who approves, which weekdays are
 *   working days, and the bonus tables by tenure and by job level.
 * - `leave_types` — what a person may be absent for, each with its own rules
 *   (paid, counted against the quota, from day one or after probation, a
 *   maximum per request, when a document is needed, half days).
 * - `leave_holidays` — the days the organization is closed: public holidays
 *   and collective leave. Not a leave type: nobody applies for them.
 *
 * The requests themselves arrive with the next slice; this one is the shape
 * every later rule is checked against.
 *
 * `employee_profiles.job_level` is added for the level bonus. `hired_at` has
 * existed since Phase 1 and is what tenure is counted from.
 */
return new class extends Migration
{
    private const PREDICATE = "organization_id = NULLIF(current_setting('app.organization_id', true), '')::uuid";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE leave_policies (
                organization_id         uuid          PRIMARY KEY
                                        REFERENCES organizations (id) ON DELETE CASCADE,
                preset                  varchar(20)   NULL,
                period                  varchar(20)   NOT NULL DEFAULT 'calendar_year',
                accrual                 varchar(30)   NOT NULL DEFAULT 'monthly_first_year',
                base_days               numeric(4,1)  NOT NULL DEFAULT 12,
                probation_months        smallint      NOT NULL DEFAULT 3,
                carry_over_max_days     numeric(4,1)  NOT NULL DEFAULT 0,
                carry_over_until_month  smallint      NULL,
                approval                varchar(20)   NOT NULL DEFAULT 'manager',
                working_days            smallint[]    NOT NULL DEFAULT '{1,2,3,4,5}',
                tenure_bonus            jsonb         NOT NULL DEFAULT '[]',
                level_bonus             jsonb         NOT NULL DEFAULT '{}',
                updated_by_membership_id uuid         NULL,
                created_at              timestamptz   NOT NULL DEFAULT now(),
                updated_at              timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_leave_policies_period
                    CHECK (period IN ('calendar_year', 'hire_anniversary')),
                CONSTRAINT ck_leave_policies_accrual
                    CHECK (accrual IN ('upfront', 'monthly', 'monthly_first_year')),
                CONSTRAINT ck_leave_policies_base
                    CHECK (base_days >= 0 AND base_days <= 365),
                CONSTRAINT ck_leave_policies_probation
                    CHECK (probation_months >= 0 AND probation_months <= 24),
                CONSTRAINT ck_leave_policies_carry
                    CHECK (carry_over_max_days >= 0 AND carry_over_max_days <= 365),
                CONSTRAINT ck_leave_policies_carry_until
                    CHECK (carry_over_until_month IS NULL OR carry_over_until_month BETWEEN 1 AND 12),
                CONSTRAINT ck_leave_policies_approval
                    CHECK (approval IN ('manager', 'hr', 'manager_then_hr')),
                CONSTRAINT ck_leave_policies_working_days
                    CHECK (cardinality(working_days) BETWEEN 1 AND 7
                       AND working_days <@ '{1,2,3,4,5,6,7}'::smallint[]),
                CONSTRAINT ck_leave_policies_tenure
                    CHECK (jsonb_typeof(tenure_bonus) = 'array'),
                CONSTRAINT ck_leave_policies_level
                    CHECK (jsonb_typeof(level_bonus) = 'object')
            );

            CREATE TABLE leave_types (
                id                      uuid          PRIMARY KEY,
                organization_id         uuid          NOT NULL
                                        REFERENCES organizations (id) ON DELETE CASCADE,
                key                     varchar(40)   NOT NULL,
                name                    varchar(80)   NOT NULL,
                paid                    boolean       NOT NULL DEFAULT true,
                uses_quota              boolean       NOT NULL DEFAULT false,
                after_probation         boolean       NOT NULL DEFAULT false,
                day_basis               varchar(20)   NOT NULL DEFAULT 'working_days',
                max_days_per_request    smallint      NULL,
                attachment_after_days   smallint      NULL,
                allow_half_day          boolean       NOT NULL DEFAULT false,
                is_active               boolean       NOT NULL DEFAULT true,
                sort_order              smallint      NOT NULL DEFAULT 0,
                created_at              timestamptz   NOT NULL DEFAULT now(),
                updated_at              timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_leave_types_key
                    CHECK (key ~ '^[a-z][a-z0-9_]{1,39}$'),
                CONSTRAINT ck_leave_types_name
                    CHECK (length(btrim(name)) >= 1),
                CONSTRAINT ck_leave_types_day_basis
                    CHECK (day_basis IN ('working_days', 'calendar_days')),
                CONSTRAINT ck_leave_types_max
                    CHECK (max_days_per_request IS NULL OR max_days_per_request BETWEEN 1 AND 366),
                CONSTRAINT ck_leave_types_attachment
                    CHECK (attachment_after_days IS NULL OR attachment_after_days BETWEEN 0 AND 366)
            );

            CREATE UNIQUE INDEX uq_leave_types_org_id ON leave_types (organization_id, id);
            CREATE UNIQUE INDEX uq_leave_types_org_key ON leave_types (organization_id, key);

            CREATE TABLE leave_holidays (
                id                      uuid          PRIMARY KEY,
                organization_id         uuid          NOT NULL
                                        REFERENCES organizations (id) ON DELETE CASCADE,
                on_date                 date          NOT NULL,
                name                    varchar(120)  NOT NULL,
                kind                    varchar(20)   NOT NULL DEFAULT 'public',
                created_at              timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_leave_holidays_kind
                    CHECK (kind IN ('public', 'collective')),
                CONSTRAINT ck_leave_holidays_name
                    CHECK (length(btrim(name)) >= 1)
            );

            CREATE UNIQUE INDEX uq_leave_holidays_org_date ON leave_holidays (organization_id, on_date);

            ALTER TABLE employee_profiles
                ADD COLUMN job_level varchar(20) NULL,
                ADD CONSTRAINT ck_employee_profiles_job_level
                    CHECK (job_level IS NULL OR job_level IN ('staff', 'supervisor', 'manager', 'director'));
        SQL);

        foreach (['leave_policies', 'leave_types', 'leave_holidays'] as $table) {
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
        DB::unprepared(<<<'SQL'
            ALTER TABLE employee_profiles
                DROP CONSTRAINT IF EXISTS ck_employee_profiles_job_level,
                DROP COLUMN IF EXISTS job_level;
            DROP TABLE IF EXISTS leave_holidays CASCADE;
            DROP TABLE IF EXISTS leave_types CASCADE;
            DROP TABLE IF EXISTS leave_policies CASCADE;
        SQL);
    }
};

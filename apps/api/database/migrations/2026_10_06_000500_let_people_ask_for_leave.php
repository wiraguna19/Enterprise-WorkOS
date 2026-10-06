<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A request for time off, and its decision (ADR 0063, slice 2).
 *
 * `days` is counted once, when the request is made, under the rules of that
 * day — working days or calendar days, holidays skipped, half a day for a
 * half day — and stored. A balance is a sum of these, so a holiday added
 * later does not quietly change what somebody already took.
 *
 * `step` is where an undecided request waits: its person's manager, or HR.
 * Null once decided.
 */
return new class extends Migration
{
    private const PREDICATE = "organization_id = NULLIF(current_setting('app.organization_id', true), '')::uuid";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE leave_requests (
                id                      uuid          PRIMARY KEY,
                organization_id         uuid          NOT NULL
                                        REFERENCES organizations (id) ON DELETE CASCADE,
                membership_id           uuid          NOT NULL,
                leave_type_id           uuid          NOT NULL,
                starts_on               date          NOT NULL,
                ends_on                 date          NOT NULL,
                half_day                varchar(2)    NULL,
                days                    numeric(5,1)  NOT NULL,
                reason                  text          NULL,
                document_required       boolean       NOT NULL DEFAULT false,
                status                  varchar(20)   NOT NULL DEFAULT 'pending',
                step                    varchar(20)   NULL,
                decided_by_membership_id uuid         NULL,
                decided_at              timestamptz   NULL,
                decision_note           text          NULL,
                created_at              timestamptz   NOT NULL DEFAULT now(),
                updated_at              timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_leave_requests_range CHECK (ends_on >= starts_on),
                CONSTRAINT ck_leave_requests_half_day
                    CHECK (half_day IS NULL OR (half_day IN ('am', 'pm') AND starts_on = ends_on)),
                CONSTRAINT ck_leave_requests_days CHECK (days > 0),
                CONSTRAINT ck_leave_requests_reason CHECK (reason IS NULL OR length(reason) <= 1000),
                CONSTRAINT ck_leave_requests_status
                    CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
                CONSTRAINT ck_leave_requests_step
                    CHECK ((status = 'pending') = (step IS NOT NULL) AND (step IS NULL OR step IN ('manager', 'hr'))),

                CONSTRAINT fk_leave_requests_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_leave_requests_type
                    FOREIGN KEY (organization_id, leave_type_id)
                    REFERENCES leave_types (organization_id, id)
            );

            CREATE INDEX idx_leave_requests_person
                ON leave_requests (organization_id, membership_id, starts_on);
            CREATE INDEX idx_leave_requests_pending
                ON leave_requests (organization_id, step) WHERE status = 'pending';
            CREATE INDEX idx_leave_requests_range
                ON leave_requests (organization_id, starts_on, ends_on) WHERE status = 'approved';
        SQL);

        DB::unprepared(sprintf(
            'ALTER TABLE leave_requests ENABLE ROW LEVEL SECURITY;'
            .' DROP POLICY IF EXISTS tenant_isolation ON leave_requests;'
            .' CREATE POLICY tenant_isolation ON leave_requests TO workos_tenant USING (%1$s) WITH CHECK (%1$s);',
            self::PREDICATE,
        ));
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS leave_requests CASCADE;');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Exports of the four reports (docs/10, Phase 6; ADR 0011).
 *
 * A row rather than a response, because a queued job cannot stream bytes to a
 * browser. The request records what was asked for and comes back immediately;
 * the file arrives later and is fetched through the same presigned-URL path
 * uploads already use (docs/03 §5), so the bucket stays private.
 *
 * **One row per REQUESTER**, never per organization. The contents of an export
 * depend on who asked — the job runs with the requester's visibility (ADR 0011)
 * — so two people exporting the same report legitimately get different files,
 * and a deduplicated row would hand the second person the first one's rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE report_exports (
                id                     uuid         PRIMARY KEY,
                organization_id        uuid         NOT NULL
                                       REFERENCES organizations (id) ON DELETE CASCADE,

                -- Whose eyes the worker used. Not merely who clicked: this is
                -- the membership the job binds with runForMembership(), and it
                -- is why the file says what it says.
                requested_by_membership_id uuid     NOT NULL,

                report_key             varchar(32)  NOT NULL,
                format                 varchar(8)   NOT NULL,
                -- What was asked for, verbatim: the window, the project, the
                -- team. Kept so a file can be explained six months later
                -- without guessing which filters produced it.
                parameters             jsonb        NOT NULL DEFAULT '{}'::jsonb,

                status                 varchar(16)  NOT NULL DEFAULT 'pending',

                storage_path           text         NULL,
                filename               varchar(200) NULL,
                byte_size              bigint       NULL,
                row_count              integer      NULL,

                -- What the reader was NOT shown, carried on the row as well as
                -- in the file: a shortfall with no explanation is the defect
                -- every drill-through in this phase avoids.
                hidden_count           integer      NULL,

                failure_reason         varchar(200) NULL,

                -- Files do not live forever. The cleanup command deletes the
                -- object and the row together; an expiry with nothing acting on
                -- it is a column that lies (docs/12 §8).
                expires_at             timestamptz  NULL,

                completed_at           timestamptz  NULL,
                created_at             timestamptz  NOT NULL DEFAULT now(),
                updated_at             timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT ck_report_exports_status
                    CHECK (status IN ('pending','ready','failed','expired')),
                CONSTRAINT ck_report_exports_format
                    CHECK (format IN ('csv','xlsx')),
                CONSTRAINT ck_report_exports_key
                    CHECK (report_key IN ('project','team','personal','organization')),

                -- Status and its evidence must agree. A "ready" export with no
                -- file is a download button that 404s, and a "failed" one with
                -- no reason is a support ticket nobody can answer.
                CONSTRAINT ck_report_exports_ready
                    CHECK ((status <> 'ready') OR
                           (storage_path IS NOT NULL AND filename IS NOT NULL
                            AND completed_at IS NOT NULL)),
                CONSTRAINT ck_report_exports_failed
                    CHECK ((status <> 'failed') OR failure_reason IS NOT NULL),

                CONSTRAINT fk_report_exports_requester
                    FOREIGN KEY (organization_id, requested_by_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_report_exports_org_id
                ON report_exports (organization_id, id);

            -- The list a person sees of their own exports, newest first.
            CREATE INDEX idx_report_exports_requester
                ON report_exports (organization_id, requested_by_membership_id, created_at DESC);

            -- The cleanup command's only query.
            CREATE INDEX idx_report_exports_expiry
                ON report_exports (expires_at)
                WHERE expires_at IS NOT NULL AND status = 'ready';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS report_exports;');
    }
};

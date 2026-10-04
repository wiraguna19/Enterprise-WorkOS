<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Something said to a group, and who has read it (ADR 0061).
 *
 * The audience is one column pair rather than three nullable foreign keys: an
 * announcement goes to exactly one group, and a CHECK that says so is shorter
 * than one that forbids every wrong combination of three columns. The ids are
 * not foreign keys for the same reason — a department that is later archived
 * keeps what was said to it, and the service checks the group exists when the
 * announcement is published.
 */
return new class extends Migration
{
    private const PREDICATE = "organization_id = NULLIF(current_setting('app.organization_id', true), '')::uuid";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE announcements (
                id                          uuid          PRIMARY KEY,
                organization_id             uuid          NOT NULL
                                            REFERENCES organizations (id) ON DELETE CASCADE,
                author_membership_id        uuid          NOT NULL,
                audience_type               varchar(20)   NOT NULL,
                audience_id                 uuid          NULL,
                title                       varchar(160)  NOT NULL,
                body                        text          NOT NULL,
                pinned                      boolean       NOT NULL DEFAULT false,
                requires_acknowledgement    boolean       NOT NULL DEFAULT false,
                published_at                timestamptz   NOT NULL,
                expires_at                  timestamptz   NULL,
                created_at                  timestamptz   NOT NULL DEFAULT now(),
                updated_at                  timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_announcements_audience_type
                    CHECK (audience_type IN ('organization', 'department', 'team')),
                CONSTRAINT ck_announcements_audience_id
                    CHECK ((audience_type = 'organization') = (audience_id IS NULL)),
                CONSTRAINT ck_announcements_title
                    CHECK (length(btrim(title)) >= 1),
                CONSTRAINT ck_announcements_body
                    CHECK (length(body) <= 5000),
                CONSTRAINT ck_announcements_expiry
                    CHECK (expires_at IS NULL OR expires_at > published_at),

                CONSTRAINT fk_announcements_author
                    FOREIGN KEY (organization_id, author_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_announcements_org_id ON announcements (organization_id, id);
            CREATE INDEX idx_announcements_feed
                ON announcements (organization_id, pinned DESC, published_at DESC);
            CREATE INDEX idx_announcements_audience
                ON announcements (organization_id, audience_type, audience_id);

            -- Read and acknowledged are two different acts (ADR 0061): opening
            -- the list reads, a button acknowledges. One row per person.
            CREATE TABLE announcement_reads (
                organization_id     uuid         NOT NULL
                                    REFERENCES organizations (id) ON DELETE CASCADE,
                announcement_id     uuid         NOT NULL,
                membership_id       uuid         NOT NULL,
                read_at             timestamptz  NOT NULL DEFAULT now(),
                acknowledged_at     timestamptz  NULL,

                PRIMARY KEY (announcement_id, membership_id),

                CONSTRAINT fk_announcement_reads_announcement
                    FOREIGN KEY (organization_id, announcement_id)
                    REFERENCES announcements (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_announcement_reads_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            CREATE INDEX idx_announcement_reads_mine
                ON announcement_reads (organization_id, membership_id);
        SQL);

        foreach (['announcements', 'announcement_reads'] as $table) {
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
        DB::unprepared('DROP TABLE IF EXISTS announcement_reads CASCADE; DROP TABLE IF EXISTS announcements CASCADE;');
    }
};

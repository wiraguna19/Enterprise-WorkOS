<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Comments, mentions, files, attachments, tags, saved views (docs/03 §5).
 *
 * Comments and attachments are polymorphic over subjects rather than bound to
 * work items: the same machinery must serve projects and, later, documents and
 * approvals, without a second implementation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE comments (
                id                  uuid         PRIMARY KEY,
                organization_id     uuid         NOT NULL
                                    REFERENCES organizations (id) ON DELETE CASCADE,
                commentable_type    varchar(40)  NOT NULL,
                commentable_id      uuid         NOT NULL,
                parent_id           uuid         NULL,
                author_membership_id uuid        NULL,

                -- The markdown source is what the author wrote and what they
                -- edit. The rendered HTML is produced server-side through a
                -- strict allowlist sanitizer and cached here, so the client
                -- never renders untrusted markup and never has to trust its own
                -- sanitizer (docs/06 §3).
                body_markdown       text         NOT NULL,
                body_html           text         NOT NULL DEFAULT '',

                edited_at           timestamptz  NULL,
                created_at          timestamptz  NOT NULL DEFAULT now(),
                updated_at          timestamptz  NOT NULL DEFAULT now(),
                deleted_at          timestamptz  NULL,

                CONSTRAINT ck_comments_subject
                    CHECK (commentable_type IN ('work_item','project','milestone')),
                CONSTRAINT ck_comments_body_not_blank
                    CHECK (length(btrim(body_markdown)) > 0),

                CONSTRAINT fk_comments_author
                    FOREIGN KEY (organization_id, author_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE SET NULL
            );

            CREATE UNIQUE INDEX uq_comments_org_id ON comments (organization_id, id);

            ALTER TABLE comments
                ADD CONSTRAINT fk_comments_parent
                FOREIGN KEY (organization_id, parent_id)
                REFERENCES comments (organization_id, id) ON DELETE CASCADE;

            -- The thread for one subject, oldest first — the only read pattern
            -- that matters here.
            CREATE INDEX idx_comments_subject
                ON comments (organization_id, commentable_type, commentable_id, created_at)
                WHERE deleted_at IS NULL;
        SQL);

        // ── mentions ────────────────────────────────────────────────────────
        // Extracted server-side from the markdown, never trusted from the
        // client: a client-supplied mention list is a notification-spam vector.
        DB::unprepared(<<<'SQL'
            CREATE TABLE mentions (
                id                     uuid        PRIMARY KEY,
                organization_id        uuid        NOT NULL,
                comment_id             uuid        NOT NULL,
                mentioned_membership_id uuid       NULL,
                mentioned_team_id      uuid        NULL,
                read_at                timestamptz NULL,
                created_at             timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT ck_mentions_subject
                    CHECK ((mentioned_membership_id IS NULL) <> (mentioned_team_id IS NULL)),

                CONSTRAINT fk_mentions_comment
                    FOREIGN KEY (organization_id, comment_id)
                    REFERENCES comments (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_mentions_membership
                    FOREIGN KEY (organization_id, mentioned_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_mentions_team
                    FOREIGN KEY (organization_id, mentioned_team_id)
                    REFERENCES teams (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_mentions_unique
                ON mentions (comment_id, COALESCE(mentioned_membership_id, mentioned_team_id));
            CREATE INDEX idx_mentions_unread
                ON mentions (organization_id, mentioned_membership_id)
                WHERE read_at IS NULL;
        SQL);

        // ── files and attachments: two tables, deliberately ─────────────────
        // A FILE is a stored blob with a checksum. An ATTACHMENT is a link from
        // a subject to that file. The same file attached twice is one blob, and
        // versioning an attachment does not duplicate storage (docs/03 §5).
        DB::unprepared(<<<'SQL'
            CREATE TABLE files (
                id                      uuid          PRIMARY KEY,
                organization_id         uuid          NOT NULL
                                        REFERENCES organizations (id) ON DELETE CASCADE,
                disk                    varchar(40)   NOT NULL DEFAULT 's3',
                path                    varchar(512)  NOT NULL,
                original_name           varchar(255)  NOT NULL,
                mime_type               varchar(160)  NOT NULL,
                size_bytes              bigint        NOT NULL,
                checksum_sha256         char(64)      NULL,
                uploaded_by_membership_id uuid        NULL,

                -- Uploads are quarantined until scanned. A file that is not
                -- clean cannot be downloaded — the failure mode is "not yet
                -- available", never "served an infected file" (docs/06 §3).
                scan_status             varchar(20)   NOT NULL DEFAULT 'pending',
                upload_state            varchar(20)   NOT NULL DEFAULT 'pending',

                created_at              timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_files_scan_status
                    CHECK (scan_status IN ('pending','clean','infected','skipped')),
                CONSTRAINT ck_files_upload_state
                    CHECK (upload_state IN ('pending','complete','failed')),
                CONSTRAINT ck_files_size
                    CHECK (size_bytes > 0 AND size_bytes <= 5368709120),

                CONSTRAINT fk_files_uploader
                    FOREIGN KEY (organization_id, uploaded_by_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE SET NULL
            );

            CREATE UNIQUE INDEX uq_files_org_id ON files (organization_id, id);
            CREATE UNIQUE INDEX uq_files_path ON files (disk, path);
            -- Deduplication by content, scoped to the tenant: identical bytes in
            -- two organizations must remain two separate blobs.
            CREATE INDEX idx_files_checksum
                ON files (organization_id, checksum_sha256)
                WHERE checksum_sha256 IS NOT NULL;

            CREATE TABLE attachments (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL,
                file_id         uuid         NOT NULL,
                attachable_type varchar(40)  NOT NULL,
                attachable_id   uuid         NOT NULL,
                version         smallint     NOT NULL DEFAULT 1,
                attached_by     uuid         NULL,
                created_at      timestamptz  NOT NULL DEFAULT now(),
                deleted_at      timestamptz  NULL,

                CONSTRAINT ck_attachments_subject
                    CHECK (attachable_type IN ('work_item','project','comment','milestone')),

                CONSTRAINT fk_attachments_file
                    FOREIGN KEY (organization_id, file_id)
                    REFERENCES files (organization_id, id) ON DELETE RESTRICT
            );

            CREATE INDEX idx_attachments_subject
                ON attachments (organization_id, attachable_type, attachable_id)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_attachments_file ON attachments (file_id);
        SQL);

        // ── tags and saved views ────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TABLE tags (
                id              uuid        PRIMARY KEY,
                organization_id uuid        NOT NULL
                                REFERENCES organizations (id) ON DELETE CASCADE,
                name            varchar(60) NOT NULL,
                color           varchar(20) NOT NULL DEFAULT 'neutral',
                created_at      timestamptz NOT NULL DEFAULT now()
            );

            CREATE UNIQUE INDEX uq_tags_org_id ON tags (organization_id, id);
            CREATE UNIQUE INDEX uq_tags_name ON tags (organization_id, lower(name));

            CREATE TABLE taggables (
                organization_id uuid        NOT NULL,
                tag_id          uuid        NOT NULL,
                taggable_type   varchar(40) NOT NULL,
                taggable_id     uuid        NOT NULL,

                PRIMARY KEY (tag_id, taggable_type, taggable_id),

                CONSTRAINT fk_taggables_tag
                    FOREIGN KEY (organization_id, tag_id)
                    REFERENCES tags (organization_id, id) ON DELETE CASCADE
            );

            CREATE INDEX idx_taggables_subject
                ON taggables (organization_id, taggable_type, taggable_id);
        SQL);

        // A stored filter + grouping + sort definition. Cheap, and it is what
        // turns a rigid page into a workspace — without it every useful filter
        // combination eventually becomes a hardcoded page (docs/03 §7).
        DB::unprepared(<<<'SQL'
            CREATE TABLE saved_views (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL
                                REFERENCES organizations (id) ON DELETE CASCADE,
                owner_membership_id uuid     NULL,
                scope           varchar(20)  NOT NULL DEFAULT 'personal',
                name            varchar(80)  NOT NULL,
                surface         varchar(40)  NOT NULL,
                definition      jsonb        NOT NULL,
                position        smallint     NOT NULL DEFAULT 0,
                created_at      timestamptz  NOT NULL DEFAULT now(),
                updated_at      timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT ck_saved_views_scope
                    CHECK (scope IN ('personal','team','organization')),
                -- A personal view must have an owner; a shared one must not
                -- disappear when its creator leaves.
                CONSTRAINT ck_saved_views_owner
                    CHECK (scope <> 'personal' OR owner_membership_id IS NOT NULL),

                CONSTRAINT fk_saved_views_owner
                    FOREIGN KEY (organization_id, owner_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE SET NULL
            );

            CREATE INDEX idx_saved_views_owner
                ON saved_views (organization_id, owner_membership_id, surface);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS saved_views CASCADE;
            DROP TABLE IF EXISTS taggables CASCADE;
            DROP TABLE IF EXISTS tags CASCADE;
            DROP TABLE IF EXISTS attachments CASCADE;
            DROP TABLE IF EXISTS files CASCADE;
            DROP TABLE IF EXISTS mentions CASCADE;
            DROP TABLE IF EXISTS comments CASCADE;
        SQL);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notifications (docs/03 §5).
 *
 * The design problem here is not delivery, it is RESTRAINT. A system that
 * notifies on everything gets muted, and then approvals are missed — which
 * means the notification system has broken the workflow it exists to support
 * (docs/12 §10). Everything below is shaped by that: granular preferences,
 * digest by default for low-signal events, and never notifying someone about
 * their own action.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE notifications (
                id                  uuid         NOT NULL,
                organization_id     uuid         NOT NULL,
                membership_id       uuid         NOT NULL,
                type                varchar(60)  NOT NULL,
                subject_type        varchar(40)  NOT NULL,
                subject_id          uuid         NOT NULL,
                actor_membership_id uuid         NULL,

                -- A RENDERED-SAFE snapshot: actor name, subject title and
                -- reference as they were. The inbox then renders without N
                -- joins, and a notification still reads correctly after the
                -- subject is renamed or the actor leaves (docs/03 §5).
                payload             jsonb        NOT NULL DEFAULT '{}'::jsonb,

                -- The natural key that makes delivery idempotent. A queue that
                -- redelivers after a lost ack must not produce a second
                -- notification — duplicate emails are the classic failure
                -- (docs/01 §4).
                dedupe_key          varchar(200) NOT NULL,

                read_at             timestamptz  NULL,
                archived_at         timestamptz  NULL,
                emailed_at          timestamptz  NULL,
                created_at          timestamptz  NOT NULL DEFAULT now(),

                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at);

            -- The unread badge runs on every page load, so it gets a partial
            -- index over exactly the rows it counts.
            CREATE INDEX idx_notifications_unread
                ON notifications (organization_id, membership_id, created_at DESC)
                WHERE read_at IS NULL AND archived_at IS NULL;

            CREATE INDEX idx_notifications_inbox
                ON notifications (organization_id, membership_id, created_at DESC);

            CREATE INDEX idx_notifications_subject
                ON notifications (subject_type, subject_id);
        SQL);

        // ── preferences ─────────────────────────────────────────────────────
        // Per person, per type. The absence of a row means "use the default for
        // this type", so a new notification type does not require backfilling a
        // row for every member of every organization.
        DB::unprepared(<<<'SQL'
            CREATE TABLE notification_preferences (
                id              uuid        PRIMARY KEY,
                organization_id uuid        NOT NULL,
                membership_id   uuid        NOT NULL,
                type            varchar(60) NOT NULL,
                in_app          boolean     NOT NULL DEFAULT true,
                email           boolean     NOT NULL DEFAULT false,
                digest          varchar(20) NOT NULL DEFAULT 'off',
                updated_at      timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT ck_np_digest
                    CHECK (digest IN ('off','daily','weekly')),
                -- Digest and immediate email are mutually exclusive: receiving
                -- both is exactly the noise that gets a system muted.
                CONSTRAINT ck_np_digest_or_email
                    CHECK (NOT (email AND digest <> 'off')),

                CONSTRAINT fk_np_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_np_pair
                ON notification_preferences (membership_id, type);
        SQL);

        $this->createPartitions();
    }

    private function createPartitions(): void
    {
        $start = new DateTimeImmutable('first day of this month 00:00:00');

        for ($i = -1; $i < 12; $i++) {
            $from = $start->modify("{$i} months");
            $to = $from->modify('+1 month');

            DB::unprepared(sprintf(
                'CREATE TABLE notifications_p%1$s PARTITION OF notifications
                 FOR VALUES FROM (%2$s) TO (%3$s);',
                $from->format('Y_m'),
                "'".$from->format('Y-m-d')."'",
                "'".$to->format('Y-m-d')."'",
            ));
        }

        DB::unprepared('CREATE TABLE notifications_default PARTITION OF notifications DEFAULT;');

        // The dedupe index is per partition rather than global: a unique index
        // on a partitioned table must include the partition key, and a
        // notification only needs to be unique within the month it was
        // generated. Redelivery weeks later is a different event anyway.
        $start = new DateTimeImmutable('first day of this month 00:00:00');

        for ($i = -1; $i < 12; $i++) {
            $suffix = $start->modify("{$i} months")->format('Y_m');

            DB::unprepared(
                "CREATE UNIQUE INDEX uq_notifications_dedupe_p{$suffix}
                 ON notifications_p{$suffix} (membership_id, dedupe_key);"
            );
        }

        DB::unprepared(
            'CREATE UNIQUE INDEX uq_notifications_dedupe_default
             ON notifications_default (membership_id, dedupe_key);'
        );
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS notification_preferences CASCADE;
            DROP TABLE IF EXISTS notifications CASCADE;
        SQL);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Outbound webhooks (docs/02 §7 `webhook(url)` [Phase 7], ADR 0048).
 *
 * ADR 0014 kept `webhook` out of the rule engine on purpose: it "lets
 * customer-authored data reach the internet from inside the queue", and it
 * needed a bound before it needed a form. These two tables are the bound.
 *
 * ## A rule names an ENDPOINT, never a URL
 *
 * docs/02 writes the action as `webhook(url)`. Taken literally, anybody who may
 * write a rule may send this organization's work to any address they can type —
 * `workflow.manage` would quietly become "may exfiltrate". So the address lives
 * here, registered by somebody holding `webhook.manage`, and a rule may only
 * point at a row of this table. Where data may go is decided once, by the
 * person whose job it is; which rule sends what is decided by the rule author.
 *
 * ## The secret is encrypted, not digested
 *
 * Credentials this product VERIFIES are stored as digests (docs/06). A signing
 * secret is the other kind: this product USES it, on every delivery, to compute
 * an HMAC — and a digest cannot sign anything. It is encrypted with the
 * application key, exactly as the TOTP secret is (`users.mfa_secret_encrypted`),
 * and shown to a person once, when it is made.
 *
 * ## Deliveries are rows, not log lines
 *
 * "Did the receiver get it" is asked days later by somebody on the other side
 * of an integration, and a log line is not an answer anybody can read. Each
 * delivery is a row with its attempts, the last status code and the last error
 * — the same reasoning that gave rules a run log that shows skips.
 *
 * Not partitioned, although it will grow: retention for this table belongs to
 * Phase 7's "data export and retention" item, and a partition scheme chosen
 * before anything reads the table would be a guess. Named as owed in ADR 0048.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE webhook_endpoints (
                id                 uuid          PRIMARY KEY,
                organization_id    uuid          NOT NULL
                                   REFERENCES organizations (id) ON DELETE CASCADE,

                name               varchar(80)   NOT NULL,

                -- https only. Checked here as well as by the guard that runs
                -- before every send, because a row that says http:// is a row
                -- some future code path will send in the clear.
                url                varchar(2000) NOT NULL,

                -- Encrypted by the application (see the class docblock). Never
                -- serialised: the model hides it and no resource names it.
                secret_encrypted   text          NOT NULL,

                is_active          boolean       NOT NULL DEFAULT true,

                -- Consecutive ABANDONED deliveries — ones that exhausted their
                -- retries. The same posture as a rule's failure count: an
                -- endpoint that keeps refusing switches itself off with the
                -- reason written down, rather than queueing work forever for a
                -- receiver that is gone.
                failure_count      integer       NOT NULL DEFAULT 0,
                disabled_reason    varchar(500)  NULL,

                created_by_membership_id uuid    NULL,

                created_at         timestamptz   NOT NULL DEFAULT now(),
                updated_at         timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_whe_https
                    CHECK (url ~ '^https://'),
                CONSTRAINT ck_whe_failure_count
                    CHECK (failure_count >= 0),
                CONSTRAINT fk_whe_creator
                    FOREIGN KEY (organization_id, created_by_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE SET NULL
            );

            -- The rule builder offers endpoints by name. Two called "Slack"
            -- are one choice rendered twice.
            CREATE UNIQUE INDEX uq_whe_name
                ON webhook_endpoints (organization_id, lower(name));

            CREATE UNIQUE INDEX uq_webhook_endpoints_org_id
                ON webhook_endpoints (organization_id, id);
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TABLE webhook_deliveries (
                id                 uuid          PRIMARY KEY,
                organization_id    uuid          NOT NULL
                                   REFERENCES organizations (id) ON DELETE CASCADE,
                endpoint_id        uuid          NOT NULL,

                event              varchar(60)   NOT NULL,

                -- What makes sending idempotent. The queue redelivers and a
                -- rule's actions may run twice for one change; the second
                -- insert for the same key is a no-op at the database, not a
                -- read-then-write two workers can both pass.
                dedupe_key         varchar(200)  NOT NULL,

                -- The `data` half of the body, exactly as it will be sent —
                -- kept so "what did you send us" has a literal answer.
                payload            jsonb         NOT NULL,

                -- pending:   waiting for its first or next attempt
                -- delivered: the receiver answered 2xx
                -- abandoned: every retry was used and none was accepted
                -- refused:   never sent — the endpoint was switched off, or its
                --            address resolves somewhere this product will not
                --            send to. Retrying cannot change either.
                status             varchar(20)   NOT NULL DEFAULT 'pending',
                attempts           smallint      NOT NULL DEFAULT 0,
                last_status_code   smallint      NULL,
                last_error         varchar(500)  NULL,

                -- Doubles as a lease: an attempt claims the row by moving this
                -- into the future, so a second worker holding the same job
                -- finds it not yet due and leaves it alone.
                next_attempt_at    timestamptz   NULL,
                delivered_at       timestamptz   NULL,

                created_at         timestamptz   NOT NULL DEFAULT now(),
                updated_at         timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_whd_status
                    CHECK (status IN ('pending', 'delivered', 'abandoned', 'refused')),
                CONSTRAINT ck_whd_attempts
                    CHECK (attempts >= 0),
                CONSTRAINT fk_whd_endpoint
                    FOREIGN KEY (organization_id, endpoint_id)
                    REFERENCES webhook_endpoints (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_whd_once
                ON webhook_deliveries (endpoint_id, dedupe_key);

            -- The endpoint screen's only question: the latest deliveries.
            CREATE INDEX idx_whd_recent
                ON webhook_deliveries (organization_id, endpoint_id, created_at DESC);

            CREATE UNIQUE INDEX uq_webhook_deliveries_org_id
                ON webhook_deliveries (organization_id, id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS webhook_deliveries CASCADE;
            DROP TABLE IF EXISTS webhook_endpoints CASCADE;
        SQL);
    }
};

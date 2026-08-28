<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Subscription URLs for external calendars (docs/10, Phase 5).
 *
 * A calendar client cannot present a bearer token, so the URL itself is the
 * credential. That makes this table a store of credentials, and it follows the
 * same rule sessions do: only the DIGEST is kept, so a database leak yields no
 * working feed URLs (docs/06 §1). The consequence is deliberate — the URL is
 * shown once, at creation, and a lost one is regenerated rather than recovered.
 *
 * One feed per membership. Two people in the same organization get different
 * URLs, and a feed shows only what its owner can already see, so a leaked URL
 * exposes one person's work rather than the tenant's.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE calendar_feeds (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL
                                REFERENCES organizations (id) ON DELETE CASCADE,
                membership_id   uuid         NOT NULL,

                token_hash      char(64)     NOT NULL,

                -- Answers "is anything actually subscribed to this?", which is
                -- the question behind every "can I revoke it" (docs/06 §1).
                last_accessed_at timestamptz NULL,
                created_at      timestamptz  NOT NULL DEFAULT now(),
                updated_at      timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT fk_calendar_feeds_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_calendar_feeds_org_id
                ON calendar_feeds (organization_id, id);

            -- One per person: regenerating replaces rather than accumulates,
            -- so an old URL stops working the moment a new one is issued.
            CREATE UNIQUE INDEX uq_calendar_feeds_membership
                ON calendar_feeds (membership_id);

            -- The feed request arrives with no tenant and no session: the digest
            -- is the only thing to look it up by.
            CREATE UNIQUE INDEX uq_calendar_feeds_token
                ON calendar_feeds (token_hash);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS calendar_feeds;');
    }
};

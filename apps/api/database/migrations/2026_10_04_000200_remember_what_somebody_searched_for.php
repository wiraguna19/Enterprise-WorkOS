<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What a person searched for and then opened, so the palette can offer it
 * back before they type.
 *
 * Kept per membership, not per user: a search in one organization is a fact
 * about that organization's work and says nothing to another. Ten at most —
 * the service prunes on every write — so this is a short memory of habits, not
 * a log of everything somebody ever looked for. A person can clear it, and
 * erasure clears it with the rest of what the organization holds about them.
 */
return new class extends Migration
{
    private const PREDICATE = "organization_id = NULLIF(current_setting('app.organization_id', true), '')::uuid";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE recent_searches (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL
                                REFERENCES organizations (id) ON DELETE CASCADE,
                membership_id   uuid         NOT NULL,
                query           varchar(200) NOT NULL,

                -- The category the search was narrowed to, or NULL for all of
                -- them. Part of what is remembered: "people named Sarah" and
                -- "everything about Sarah" are different searches.
                type            varchar(20)  NULL,

                searched_at     timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT ck_recent_searches_type
                    CHECK (type IS NULL OR type IN ('work_item', 'project', 'person')),
                CONSTRAINT ck_recent_searches_query
                    CHECK (length(btrim(query)) >= 2),

                CONSTRAINT fk_recent_searches_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            -- Searching for the same thing again moves it to the top rather
            -- than listing it twice; case is not a different search.
            CREATE UNIQUE INDEX uq_recent_searches_once
                ON recent_searches (membership_id, lower(query), COALESCE(type, ''));

            CREATE INDEX idx_recent_searches_mine
                ON recent_searches (organization_id, membership_id, searched_at DESC);
        SQL);

        DB::unprepared(sprintf(
            'ALTER TABLE recent_searches ENABLE ROW LEVEL SECURITY;'
            .' DROP POLICY IF EXISTS tenant_isolation ON recent_searches;'
            .' CREATE POLICY tenant_isolation ON recent_searches TO workos_tenant USING (%1$s) WITH CHECK (%1$s);',
            self::PREDICATE,
        ));
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS recent_searches CASCADE;');
    }
};

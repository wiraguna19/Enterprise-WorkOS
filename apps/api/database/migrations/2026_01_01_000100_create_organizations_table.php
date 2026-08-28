<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrations in this project are written as raw SQL rather than through the
 * Schema builder. This is deliberate: the schema depends on partial indexes,
 * range partitioning, composite foreign keys, CHECK constraints, expression
 * indexes, and triggers — none of which Laravel's builder can express. A
 * half-builder/half-raw migration is harder to read than one that is honest
 * about being SQL, and the SQL is the artifact a DBA will actually review.
 *
 * See docs/03-database-schema.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE organizations (
                id              uuid        PRIMARY KEY,
                name            varchar(160) NOT NULL,
                slug            varchar(80)  NOT NULL,
                plan            varchar(40)  NOT NULL DEFAULT 'internal',
                status          varchar(20)  NOT NULL DEFAULT 'active',
                settings        jsonb        NOT NULL DEFAULT '{}'::jsonb,
                created_at      timestamptz  NOT NULL DEFAULT now(),
                updated_at      timestamptz  NOT NULL DEFAULT now(),
                deleted_at      timestamptz  NULL,

                CONSTRAINT ck_organizations_status
                    CHECK (status IN ('active', 'suspended', 'closed')),
                CONSTRAINT ck_organizations_slug_format
                    CHECK (slug ~ '^[a-z0-9]([a-z0-9-]*[a-z0-9])?$')
            );

            CREATE UNIQUE INDEX uq_organizations_slug
                ON organizations (slug) WHERE deleted_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS organizations CASCADE;');
    }
};

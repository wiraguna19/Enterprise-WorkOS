<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Search vectors for the things people look for (docs/10, Phase 5).
 *
 * Work items already carried one from Phase 2. Projects and comments did not,
 * and the phase's own exit criterion names comment text — "find the item where
 * someone mentioned the rollback" is the search people actually run, and it is
 * the one a title-only index cannot answer.
 *
 * Generated columns rather than triggers, matching work_items: to_tsvector with
 * an explicit configuration is immutable, so PostgreSQL maintains these itself
 * and they cannot drift from the text they describe.
 *
 * Weights are deliberate. On a project, the key ("ENG") is what people type, so
 * it outranks the name, which outranks the description. On a comment there is
 * only one field, and weighting it 'A' would let a passing remark outrank a
 * work item whose title is the exact phrase — so comment text sits at 'C'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE projects
                ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                    setweight(to_tsvector('english', coalesce(key, '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(name, '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(description, '')), 'C')
                ) STORED;

            CREATE INDEX idx_projects_search ON projects USING GIN (search_vector);

            ALTER TABLE comments
                ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                    setweight(to_tsvector('english', coalesce(body_markdown, '')), 'C')
                ) STORED;

            CREATE INDEX idx_comments_search ON comments USING GIN (search_vector);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_comments_search;
            ALTER TABLE comments DROP COLUMN IF EXISTS search_vector;

            DROP INDEX IF EXISTS idx_projects_search;
            ALTER TABLE projects DROP COLUMN IF EXISTS search_vector;
        SQL);
    }
};

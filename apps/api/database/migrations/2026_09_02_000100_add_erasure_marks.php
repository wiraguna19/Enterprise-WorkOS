<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Delete my data", recorded as having happened (ADR 0022).
 *
 * Erasure here is ANONYMISATION, not deletion: the rows stay and the person
 * goes out of them. That choice needs a mark, because after it is done there is
 * nothing left in the row to tell you it was done — a user called "Deleted
 * person" with no email is indistinguishable from a broken import unless
 * something says so.
 *
 * Two marks, deliberately:
 *
 * - `memberships.erased_at` — what THIS organization did, and the only thing an
 *   organization's administrator can do.
 * - `users.erased_at` — the shared account itself, which is only reached when
 *   the membership being erased was the last one the account had.
 *
 * `erased_by` is a membership, not a user: the question asked afterwards is
 * "who in this organization did that", and the answer must survive the actor
 * later leaving. It is deliberately NOT a foreign key for the same reason the
 * audit log's actor is not — the row it points at may itself be erased later.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE memberships
                ADD COLUMN erased_at timestamptz NULL,
                ADD COLUMN erased_by uuid        NULL;

            ALTER TABLE users
                ADD COLUMN erased_at timestamptz NULL;

            -- Erased people are excluded from directories and pickers, which is
            -- a filter on the hot path of every person list in the product.
            CREATE INDEX idx_memberships_erased
                ON memberships (organization_id, erased_at)
                WHERE erased_at IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_memberships_erased;
            ALTER TABLE memberships DROP COLUMN IF EXISTS erased_at, DROP COLUMN IF EXISTS erased_by;
            ALTER TABLE users DROP COLUMN IF EXISTS erased_at;
        SQL);
    }
};

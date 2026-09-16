<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Why a session ended (ADR 0023).
 *
 * `SessionModel::revoke()` has taken a `$reason` since Phase 1 and thrown it
 * away — the parameter is there, every caller passes one, and the method body
 * writes `revoked_at` alone. `PruneExpiredSessions` even explains in its
 * docblock that revoked rows linger because "I was logged out — when, and by
 * what?" is asked in the days after, while the answer to "by what" was being
 * dropped on the floor.
 *
 * Short and constrained rather than free text: the answers are a closed set —
 * the person signed out, they ended it from another device, an administrator
 * revoked it, the account was erased — and a column that could hold a sentence
 * would eventually hold one, in a table nobody reads for prose.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE sessions ADD COLUMN revoked_reason varchar(40) NULL;

            -- Every session revoked BEFORE this column existed. Without this
            -- line the constraint below is violated by history and the
            -- migration fails on any database that has ever signed somebody
            -- out — which the test suite cannot show you, because it builds the
            -- schema from nothing every run. The first installation it met said
            -- so immediately.
            --
            -- `unrecorded` rather than a guessed `signed_out`: the reason
            -- genuinely was not kept, and a migration that invents history to
            -- satisfy its own constraint is worse than one that admits the gap.
            UPDATE sessions SET revoked_reason = 'unrecorded' WHERE revoked_at IS NOT NULL;

            ALTER TABLE sessions ADD CONSTRAINT ck_sessions_revoked_reason
                CHECK (
                    (revoked_at IS NULL AND revoked_reason IS NULL)
                    OR (revoked_at IS NOT NULL AND revoked_reason IS NOT NULL)
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE sessions DROP CONSTRAINT IF EXISTS ck_sessions_revoked_reason;
            ALTER TABLE sessions DROP COLUMN IF EXISTS revoked_reason;
        SQL);
    }
};

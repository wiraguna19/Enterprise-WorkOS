<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * When this session last proved who is holding it (ADR 0034).
 *
 * `sessions` already records when it began and when it was last USED. Neither
 * answers the question a sensitive act has to ask: not "is this session valid"
 * — that is settled before any of this runs — but "did the person prove
 * themselves recently enough that I should let them erase somebody".
 *
 * Backfilled from `created_at` rather than left null. A session that signed in
 * an hour ago DID prove itself an hour ago, and pretending otherwise would
 * greet every person in the product with a password prompt on the morning this
 * deploys — which is how a good safeguard teaches people to type their password
 * into anything that asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE sessions ADD COLUMN reauthenticated_at timestamptz NULL;

            UPDATE sessions SET reauthenticated_at = created_at;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE sessions DROP COLUMN IF EXISTS reauthenticated_at;');
    }
};

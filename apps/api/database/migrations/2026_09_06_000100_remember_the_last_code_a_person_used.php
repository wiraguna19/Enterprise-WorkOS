<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The counter of the last one-time password that worked (ADR 0030).
 *
 * `users` has carried `mfa_secret_encrypted`, `mfa_enabled_at` and
 * `mfa_recovery_codes` since Phase 1 — three columns nothing has ever written,
 * while `/auth/me` reported `mfa_enabled: false` to every client with complete
 * confidence. This is the one column the feature needed and did not have.
 *
 * Without it a TOTP code is reusable for as long as it is valid — up to ninety
 * seconds with the drift window either side — so a code read over somebody's
 * shoulder, or lifted from a phishing page a moment earlier, signs in a second
 * time. Storing the period that was accepted makes a code single-use, which is
 * what "one-time password" is supposed to mean.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users ADD COLUMN mfa_last_counter bigint NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE users DROP COLUMN IF EXISTS mfa_last_counter;');
    }
};

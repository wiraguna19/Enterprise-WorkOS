<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An organization requiring a second factor of everybody (ADR 0033).
 *
 * `docs/06` has said TOTP is "enforceable per organization from Phase 7" since
 * Phase 1, and ADR 0030 built the mechanism while leaving the policy owed. This
 * is the policy: one boolean, off for every organization that already exists.
 *
 * Off is not timidity. Switching it on changes what everybody in the
 * organization has to do before their next request, and a migration that made
 * that decision on an administrator's behalf would be making it at three in the
 * morning for people who never agreed to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations
                ADD COLUMN require_mfa boolean NOT NULL DEFAULT false;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE organizations DROP COLUMN IF EXISTS require_mfa;');
    }
};

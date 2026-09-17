<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Signing out a session nobody is using (ADR 0029).
 *
 * `docs/06` has claimed an idle timeout of "8 hours (org-configurable)" since
 * Phase 1. Nothing has ever measured idleness: `sessions.last_used_at` is
 * written by Sanctum on every authenticated request and was read by exactly one
 * thing — the session list, to print a date. A timeout in a specification and
 * nowhere in the product is the same defect as a permission nothing consults,
 * and it is worse here because the claim is about security.
 *
 * NULL means no idle timeout, and NULL is the default — including for every
 * organization that already exists. The alternative is a migration that signs
 * out a company overnight to satisfy a number in a document nobody read, which
 * is not a default, it is an incident. Switching it on is a decision somebody
 * makes on a screen.
 *
 * Five minutes is the floor: shorter is a product that logs you out while you
 * read. Seven days is the ceiling because beyond that the absolute lifetime
 * (ADR 0028, 90 days at the most) is the thing actually ending the session, and
 * a control that cannot bite is worse than no control.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations
                ADD COLUMN idle_timeout_minutes smallint NULL;

            ALTER TABLE organizations ADD CONSTRAINT ck_organizations_idle_timeout
                CHECK (idle_timeout_minutes IS NULL OR idle_timeout_minutes BETWEEN 5 AND 10080);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations DROP CONSTRAINT IF EXISTS ck_organizations_idle_timeout;
            ALTER TABLE organizations DROP COLUMN IF EXISTS idle_timeout_minutes;
        SQL);
    }
};

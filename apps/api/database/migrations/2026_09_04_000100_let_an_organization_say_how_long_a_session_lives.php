<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * How long a session may live, per organization (ADR 0028).
 *
 * Thirty days has been written in `AuthenticationService` since Phase 1 and is
 * the same for a contractor's laptop and a finance team's workstation. ADR 0023
 * shipped the half of "session policy controls" a person can see — what is
 * signed in as me, and ending it — and recorded the other half as owed: the
 * organization cannot say anything about how long any of it lasts.
 *
 * A column, not the `settings` jsonb this table has carried since Phase 1 and
 * which nothing has ever read. A blob cannot be constrained: `{"session_lifetime_days": "7"}`
 * is a string, `{"session_lifetime_dys": 7}` is a typo, and both are accepted
 * by the database and discovered by whoever is signed out at the wrong moment.
 * The bounds below are the point of the slice, so they live where nothing can
 * route around them — not in a form request, which protects one door.
 *
 * One day is the floor because zero is not a policy, it is a lockout. Ninety is
 * the ceiling because a year-long session is indistinguishable from no expiry
 * at all, and a control whose safest setting is unreachable and whose least
 * safe setting is unbounded is not a control.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations
                ADD COLUMN session_lifetime_days smallint NOT NULL DEFAULT 30;

            ALTER TABLE organizations ADD CONSTRAINT ck_organizations_session_lifetime
                CHECK (session_lifetime_days BETWEEN 1 AND 90);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations DROP CONSTRAINT IF EXISTS ck_organizations_session_lifetime;
            ALTER TABLE organizations DROP COLUMN IF EXISTS session_lifetime_days;
        SQL);
    }
};

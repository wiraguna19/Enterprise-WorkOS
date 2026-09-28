<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Public API tokens (docs/10 Phase 7, ADR 0049).
 *
 * A token is a row in `sessions`, told apart by `kind`. Not a table of its own,
 * and the reason is everything a session row already gets for free:
 *
 * - Sanctum resolves it through `SessionModel::findToken()`, digest-only.
 * - `ResolveTenant` re-checks the MEMBERSHIP on every request, so a person who
 *   leaves the organization loses their tokens in the same request their
 *   sessions die — the property docs/06 §1 chose opaque tokens for.
 * - `RequireSecondFactor` confines it exactly as it confines a session.
 * - The revoke-all path — a second factor turned on or off — already ends
 *   every other row for the person, and a token minted before the credentials
 *   changed is exactly the thing that should stop being trusted.
 *
 * A separate table would have needed every one of those rebuilt beside the
 * original, and the copy is always the weaker door.
 *
 * What differs is written down where it is enforced, not here: a token does
 * not go idle, is not clamped by the organization's session lifetime, can never
 * re-authenticate, cannot reach `auth.*`, and cannot write unless it was made to.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Every existing row is a browser session; the default says so.
            ALTER TABLE sessions ADD COLUMN kind varchar(20) NOT NULL DEFAULT 'session';

            ALTER TABLE sessions ADD CONSTRAINT ck_sessions_kind
                CHECK (kind IN ('session', 'api_token'));

            -- The token screen's only question: this person's live tokens here.
            CREATE INDEX idx_sessions_api_tokens
                ON sessions (user_id, organization_id)
                WHERE kind = 'api_token' AND revoked_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_sessions_api_tokens;
            ALTER TABLE sessions DROP CONSTRAINT IF EXISTS ck_sessions_kind;
            ALTER TABLE sessions DROP COLUMN IF EXISTS kind;
        SQL);
    }
};

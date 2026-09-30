<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An endpoint can subscribe to events without a rule (ADR 0048, "still owed").
 *
 * A list on the endpoint, not a table of its own: the question asked is "which
 * endpoints want THIS event", answered with one containment test, and a
 * subscription has no attributes of its own to put in a row. jsonb rather than
 * text[] because Eloquent casts it to an array without a hand-written parser.
 *
 * The CHECK names the events that can be subscribed to — the ones something in
 * the product actually emits. A value outside it is a subscription that would
 * never be delivered, which is worse than a refusal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE webhook_endpoints
                ADD COLUMN IF NOT EXISTS events jsonb NOT NULL DEFAULT '[]'::jsonb;

            ALTER TABLE webhook_endpoints DROP CONSTRAINT IF EXISTS ck_whe_events;
            ALTER TABLE webhook_endpoints ADD CONSTRAINT ck_whe_events CHECK (
                jsonb_typeof(events) = 'array'
                AND events <@ '["work_item.created", "work_item.assigned", "work_item.status_changed"]'::jsonb
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE webhook_endpoints DROP CONSTRAINT IF EXISTS ck_whe_events;
            ALTER TABLE webhook_endpoints DROP COLUMN IF EXISTS events;
        SQL);
    }
};

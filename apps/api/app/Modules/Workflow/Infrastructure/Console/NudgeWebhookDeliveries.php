<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Console;

use App\Modules\Workflow\Infrastructure\Job\DeliverWebhook;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Re-dispatch webhook deliveries the queue lost (ADR 0048's owed sweeper).
 *
 * A retry is a delayed job. If the queue loses it — Redis flushed, a worker
 * killed mid-release, a deploy that drained the wrong queue — the row stays
 * `pending` with a `next_attempt_at` in the past, and nothing will ever look
 * at it again. The receiver never gets the event and the endpoint's screen
 * shows "pending" forever, which reads as "still trying".
 *
 * This finds those rows and dispatches them again. It does not send anything
 * itself: the job claims the row with the same lease every attempt uses, so a
 * delivery nudged while its own job was about to run is attempted once — the
 * second claimant finds it not yet due and leaves it alone.
 *
 * The grace period is what keeps it from racing healthy retries: a row is only
 * "lost" once it is well past due, not the second it becomes due.
 *
 * Across every organization, like the other maintenance commands: it reads
 * ids and hands each to a job that binds its own tenant.
 */
final class NudgeWebhookDeliveries extends Command
{
    /** Past due by this much before a delivery counts as lost. */
    private const GRACE_MINUTES = 5;

    /** Bounded, so a backlog is worked through over several runs rather than one flood. */
    private const LIMIT = 500;

    protected $signature = 'workflow:nudge-webhook-deliveries';

    protected $description = 'Re-dispatch pending webhook deliveries whose retry the queue lost';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(self::GRACE_MINUTES);

        $lost = DB::table('webhook_deliveries')
            ->where('status', 'pending')
            ->where(function (Builder $query) use ($cutoff): void {
                // Never attempted: the first dispatch itself went missing.
                $query->whereNull('next_attempt_at')->where('created_at', '<', $cutoff)
                    // Or a retry that was due a while ago and nobody took.
                    ->orWhere('next_attempt_at', '<', $cutoff);
            })
            ->orderBy('created_at')
            ->limit(self::LIMIT)
            ->get(['id', 'organization_id']);

        foreach ($lost as $delivery) {
            DeliverWebhook::dispatch((string) $delivery->organization_id, (string) $delivery->id);
        }

        $this->info("Nudged {$lost->count()} webhook deliveries.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled since Phase 1 and never written — the schedule entry pointed at a
 * command that did not exist, so it failed silently every night at 03:00.
 *
 * Expired and revoked sessions are deleted rather than left to accumulate. The
 * audit trail of logins lives in `audit_logs` and is untouched by this: the
 * record of who signed in is not the same thing as the credential they used.
 */
final class PruneExpiredSessions extends Command
{
    protected $signature = 'identity:prune-expired-sessions {--days=30 : How long a revoked session is kept before deletion}';

    protected $description = 'Delete sessions that can no longer authenticate anyone';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        // Expired sessions go immediately: they already authenticate nobody.
        $expired = DB::table('sessions')->where('expires_at', '<=', now())->delete();

        // Revoked ones linger briefly. "I was logged out — when, and by what?"
        // is asked in the days after, and the row is the only place that
        // answers it.
        $revoked = DB::table('sessions')
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<=', $cutoff)
            ->delete();

        $this->info("Pruned {$expired} expired and {$revoked} revoked sessions.");

        return self::SUCCESS;
    }
}

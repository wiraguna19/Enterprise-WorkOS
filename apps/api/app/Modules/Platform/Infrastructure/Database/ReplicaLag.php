<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Database;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Is the replica close enough behind the primary to answer a report? (ADR 0053)
 *
 * ADR 0053 routed reports to the replica and owed this: nothing said how far
 * behind it was, and nothing stopped a replica that had stopped replicating an
 * hour ago from answering every dashboard with an hour-old picture — or one
 * that could not be reached from turning every insight into a 500.
 *
 * **The measure is the standard one, with its standard trap avoided.**
 * `now() - pg_last_xact_replay_timestamp()` alone grows on an IDLE primary —
 * nothing to replay, so the last replay gets older — and would call a perfect
 * replica stale on a quiet night. So a replica that has replayed everything it
 * has received is 0 behind, and only one with WAL still to apply is measured
 * by the age of what it last applied. A replica with no WAL receiver at all is
 * disconnected, and its lag is unknown, not zero.
 *
 * **Unknown is not fresh.** An unreachable replica or one that is not
 * receiving answers "not acceptable", and the report reads the primary: slower
 * and correct beats fast and stale, and far beats a 500.
 *
 * **Asked at most every CHECK_SECONDS, not per request.** The verdict is
 * cached; the log line is written when it is computed, so a replica that falls
 * behind says so once per window rather than once per dashboard load.
 */
final class ReplicaLag
{
    private const CACHE_KEY = 'reporting-replica:verdict';

    private const CHECK_SECONDS = 15;

    public function __construct(
        private readonly Cache $cache,
    ) {}

    public function acceptable(): bool
    {
        /** @var bool $verdict */
        $verdict = $this->cache->remember(self::CACHE_KEY, self::CHECK_SECONDS, function (): bool {
            $lag = $this->seconds();
            $limit = self::limit();

            if ($lag === null) {
                Log::warning('Reporting replica unreachable or not replicating; reports read the primary.');

                return false;
            }

            if ($lag > $limit) {
                Log::warning('Reporting replica is behind; reports read the primary until it catches up.', [
                    'lag_seconds' => round($lag, 1),
                    'limit_seconds' => $limit,
                ]);

                return false;
            }

            return true;
        });

        return $verdict;
    }

    /**
     * How far behind the replica is, in seconds; null when that cannot be
     * known — unreachable, or in recovery with nothing streaming to it.
     */
    public function seconds(): ?float
    {
        try {
            $row = DB::connection(ReportingReplica::CONNECTION)->selectOne(<<<'SQL'
                SELECT CASE
                         WHEN NOT pg_is_in_recovery() THEN 0
                         WHEN NOT EXISTS (SELECT 1 FROM pg_stat_wal_receiver) THEN NULL
                         WHEN pg_last_wal_receive_lsn() = pg_last_wal_replay_lsn() THEN 0
                         ELSE EXTRACT(EPOCH FROM now() - pg_last_xact_replay_timestamp())
                       END AS lag
            SQL);
        } catch (Throwable) {
            return null;
        }

        $lag = is_object($row) ? ($row->lag ?? null) : null;

        return is_numeric($lag) ? (float) $lag : null;
    }

    /** `DB_REPORTING_MAX_LAG`, 30 seconds by default. */
    public static function limit(): float
    {
        $configured = config('database.reporting.max_lag_seconds');

        return is_numeric($configured) ? (float) $configured : 30.0;
    }
}

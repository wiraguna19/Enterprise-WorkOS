<?php

declare(strict_types=1);

namespace App\Modules\Governance\Infrastructure\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the monthly partitions ahead of the calendar (docs/03 §6).
 *
 * Scheduled since Phase 1 and never written. The migrations create thirteen
 * months of partitions when they run, so the failure was invisible: it only
 * bites about a year after an installation, when rows start landing in the
 * DEFAULT partition. That still works and is the reason a default exists — but
 * a default that fills up forever is a table that cannot be pruned by dropping
 * a partition, which is the whole point of partitioning these.
 *
 * Every partitioned table in the schema is listed here. A new one that forgets
 * to add itself gets a default partition and no error, which is exactly the
 * kind of silence this command exists to end — so the list is asserted against
 * the database in the test suite rather than trusted.
 */
final class EnsureLogPartitions extends Command
{
    protected $signature = 'governance:ensure-log-partitions {--months=6 : How far ahead to build}';

    protected $description = 'Create next months\' partitions for the append-only log tables';

    /**
     * Table → the column it is ranged on. Both matter: the bounds must be
     * written in the same units the partition key uses.
     *
     * @var array<string, string>
     */
    private const PARTITIONED = [
        'activity_logs' => 'occurred_at',
        'audit_logs' => 'occurred_at',
        'work_item_transitions' => 'occurred_at',
        'workflow_rule_runs' => 'occurred_at',
        'approval_decisions' => 'decided_at',
        'notifications' => 'created_at',
    ];

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $start = new DateTimeImmutable('first day of this month 00:00:00');
        $created = 0;

        foreach (array_keys(self::PARTITIONED) as $table) {
            for ($i = 0; $i <= $months; $i++) {
                $from = $start->modify("+{$i} months");
                $to = $from->modify('+1 month');
                $partition = sprintf('%s_p%s', $table, $from->format('Y_m'));

                if ($this->exists($partition)) {
                    continue;
                }

                // IF NOT EXISTS is not enough on its own: creating a partition
                // that overlaps an existing range is an error, not a no-op, so
                // the check above is the real guard and this is the race.
                DB::unprepared(sprintf(
                    "CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s');",
                    $partition,
                    $table,
                    $from->format('Y-m-d'),
                    $to->format('Y-m-d'),
                ));

                $created++;
            }
        }

        $this->info("Ensured partitions {$months} months ahead; created {$created}.");

        return self::SUCCESS;
    }

    private function exists(string $partition): bool
    {
        return DB::table('pg_class')->where('relname', $partition)->exists();
    }
}

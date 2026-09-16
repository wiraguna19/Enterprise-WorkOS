<?php

declare(strict_types=1);

namespace App\Modules\Governance\Infrastructure\Console;

use DateTimeImmutable;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The other half of partitioning (ADR 0021).
 *
 * `EnsureLogPartitions` has been creating monthly partitions since Phase 1 and
 * nothing has ever removed one. Six append-only tables grow forever, and ADR
 * 0019 named the gap while deliberately not closing it: a retention window is a
 * legal question before it is a technical one.
 *
 * It is answered in `config/governance.php`, in months per table, and enforced
 * HERE by dropping whole partitions — never by deleting rows. A DELETE over a
 * partitioned table of millions of rows is a long transaction, a table-sized
 * write to the WAL, and a vacuum afterwards; a DROP is a catalogue update. That
 * difference is the entire reason these tables are partitioned by month.
 *
 * Two consequences of dropping rather than deleting, both deliberate:
 *
 * - **Retention is whole months.** A partition is dropped only when its ENTIRE
 *   range is older than the window, so rows live up to a month longer than the
 *   window says. Trimming the edge exactly would mean the DELETE this command
 *   exists to avoid.
 * - **It is platform-wide.** A partition holds every organization's rows for
 *   that month, so there is no per-tenant window to honour here.
 *
 * The bounds are read from the catalogue rather than parsed out of partition
 * names. The names are this codebase's convention and the bound is Postgres's
 * own truth, and a pruner that trusted a name would drop the wrong table the
 * first time somebody attached a partition by hand.
 */
final class PruneLogPartitions extends Command
{
    protected $signature = 'governance:prune-log-partitions
        {--dry-run : List what would be dropped and drop nothing}';

    protected $description = 'Drop log partitions whose whole range is older than the retention window';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $dropped = 0;

        /** @var array<string, int|null> $windows */
        $windows = config('governance.retention');

        foreach ($windows as $table => $months) {
            if ($months === null) {
                continue;
            }

            $cutoff = new DateTimeImmutable("first day of this month 00:00:00 -{$months} months");

            foreach ($this->partitionsOf($table) as $partition => $upperBound) {
                // The whole range must be behind the cutoff. `<=` and not `<`:
                // a partition's upper bound is EXCLUSIVE, so one ending exactly
                // at the cutoff holds nothing the window covers.
                if ($upperBound === null || $upperBound > $cutoff) {
                    continue;
                }

                $this->line(($dryRun ? 'would drop ' : 'dropping ').$partition);

                if (! $dryRun) {
                    // `statement()` rather than `unprepared()`: one statement,
                    // and it keeps the literal-string rule in force here rather
                    // than adding this file to the list of exemptions in
                    // phpstan.neon. The partition name comes from the
                    // catalogue, never from a request.
                    DB::statement(sprintf('DROP TABLE IF EXISTS %s;', $partition));
                }

                $dropped++;
            }

            $this->reportStrandedRows($table, $cutoff);
        }

        $this->info($dryRun
            ? "Would drop {$dropped} partition(s)."
            : "Dropped {$dropped} partition(s).");

        return self::SUCCESS;
    }

    /**
     * Each partition of this table, with the exclusive upper bound of its
     * range — null for the DEFAULT partition, which has no range and is never
     * dropped.
     *
     * @return array<string, DateTimeImmutable|null>
     */
    private function partitionsOf(string $table): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT child.relname AS partition,
                   pg_get_expr(child.relpartbound, child.oid) AS bound
              FROM pg_inherits
              JOIN pg_class parent ON parent.oid = pg_inherits.inhparent
              JOIN pg_class child  ON child.oid  = pg_inherits.inhrelid
             WHERE parent.relname = ?
             ORDER BY child.relname
        SQL, [$table]);

        $partitions = [];

        foreach ($rows as $row) {
            /** @var object{partition: string, bound: string|null} $row */
            $partitions[$row->partition] = $this->upperBoundOf((string) $row->bound);
        }

        return $partitions;
    }

    /**
     * The TO bound of `FOR VALUES FROM ('…') TO ('…')`.
     *
     * Parsed with the constructor rather than `createFromFormat`, because
     * Postgres prints a `timestamptz` bound with its offset —
     * `2025-10-01 00:00:00+00` — and `createFromFormat('Y-m-d H:i:s', …)`
     * rejects the trailing offset outright. The first version did exactly that,
     * returned null for every partition in the database, and so dropped
     * nothing at all while reporting success: a pruner that silently prunes
     * nothing is the failure this whole command exists to end, and the only
     * thing that caught it was a test asserting a specific partition was gone.
     *
     * Null for DEFAULT, and null for anything unparseable — the safe direction:
     * a bound this does not understand is KEPT, never dropped.
     */
    private function upperBoundOf(string $bound): ?DateTimeImmutable
    {
        if (preg_match("/TO \('([^']+)'\)/", $bound, $matches) !== 1) {
            return null;
        }

        try {
            return new DateTimeImmutable($matches[1]);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Rows the pruner cannot reach.
     *
     * If `EnsureLogPartitions` ever stops running, writes land in the DEFAULT
     * partition — which works, and is why a default exists. But a default
     * accumulates rows from every month at once, so no single drop can retire
     * them, and a retention promise quietly stops being kept. Counting them out
     * loud is the difference between a gap and a silence.
     */
    private function reportStrandedRows(string $table, DateTimeImmutable $cutoff): void
    {
        $default = $table.'_default';

        if (! DB::table('pg_class')->where('relname', $default)->exists()) {
            return;
        }

        $column = EnsureLogPartitions::partitionColumn($table);

        if ($column === null) {
            return;
        }

        $stranded = DB::table($default)
            ->where($column, '<', $cutoff->format('Y-m-d H:i:s'))
            ->count();

        if ($stranded > 0) {
            $this->warn(
                "{$stranded} row(s) in {$default} are past the window and cannot be dropped: "
                .'they were written while no partition covered their month.'
            );
        }
    }
}

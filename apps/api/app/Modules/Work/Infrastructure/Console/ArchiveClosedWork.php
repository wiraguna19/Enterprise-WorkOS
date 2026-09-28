<?php

declare(strict_types=1);

namespace App\Modules\Work\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Take closed work out of the working set (ADR 0054).
 *
 * An item is archived when it has been done or cancelled — and untouched — for
 * longer than its organization's `archive_closed_after_days`. "Untouched" is
 * `updated_at`: an item somebody is still editing after closing it is not
 * finished with, and restoring one resets its clock the same way.
 *
 * Nothing is deleted and nothing about the item changes but the flag: it stays
 * in every report, every count of completions, every search, and opens by its
 * reference exactly as before. What changes is that the board's Done column
 * and the browse list stop carrying it.
 *
 * In batches, because the first run on an organization that has been busy for
 * years is every item it ever closed, and one UPDATE of all of them holds its
 * locks for as long as that takes. Across every organization at once: like the
 * progress rollup, this is maintenance over a column, not a read on somebody's
 * behalf, so there is no tenant to bind.
 *
 * No activity entry per item. The system archiving ten thousand rows is not ten
 * thousand things anybody did, and the detail page says when an item was
 * archived and why. Restoring one IS somebody's act, and is recorded.
 */
final class ArchiveClosedWork extends Command
{
    protected $signature = 'work:archive-closed-work {--batch=5000 : Rows per statement}';

    protected $description = 'Archive work that has been closed and untouched for longer than its organization keeps it in view';

    public function handle(): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $total = 0;

        do {
            $archived = DB::update(<<<'SQL'
                UPDATE work_items
                   SET archived_at = now()
                 WHERE id IN (
                        SELECT w.id
                          FROM work_items w
                          JOIN organizations o ON o.id = w.organization_id
                         WHERE o.archive_closed_after_days IS NOT NULL
                           AND w.archived_at IS NULL
                           AND w.deleted_at IS NULL
                           AND w.state_category IN ('done', 'cancelled')
                           AND w.updated_at < now() - make_interval(days => o.archive_closed_after_days)
                         LIMIT ?
                       )
            SQL, [$batch]);

            $total += $archived;
        } while ($archived === $batch);

        $this->info("Archived {$total} closed work items.");

        return self::SUCCESS;
    }
}

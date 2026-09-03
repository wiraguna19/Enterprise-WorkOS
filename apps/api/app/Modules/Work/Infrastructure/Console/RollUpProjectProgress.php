<?php

declare(strict_types=1);

namespace App\Modules\Work\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills `projects.progress_cache`, which nothing has ever written.
 *
 * The column, its `progress_cached_at` companion and the progress bar in the
 * project directory have existed since Phase 2 (docs/02 §5). The rollup job
 * they were declared for was never built, so every project in the directory has
 * been rendering 0% — a number that looked computed and was not. This is the
 * "declared but never built" pattern the earlier phases kept turning up, and it
 * is the most expensive shape of it: a wrong figure is worse than a missing one
 * because nobody goes looking for the bug.
 *
 * **Progress is DERIVED and cached, never authoritative** (docs/02 §5,
 * docs/12 §8). The definition is ADR 0008's, the same one the project health
 * page computes live:
 *
 *     progress = done ÷ (everything that is not cancelled)
 *
 * Cancelled work is neither delivered nor remaining, so it leaves the
 * denominator entirely — otherwise abandoning work would move a project's
 * progress bar, in either direction, depending on which side you counted it.
 *
 * Two implementations of one rule is exactly what this codebase keeps being
 * bitten by, so `ProjectProgressTest` asserts that what this writes equals what
 * `/insights/projects/{key}/health` computes live for the same project. The
 * health page does NOT read this cache: it is one project and can afford the
 * query, while the directory renders forty rows and cannot.
 *
 * Runs across every organization in one statement. There is no tenant context
 * in a scheduled command and this needs none — it is maintenance over a column,
 * not a read on somebody's behalf.
 */
final class RollUpProjectProgress extends Command
{
    protected $signature = 'work:roll-up-project-progress';

    protected $description = 'Recompute the cached completion percentage on every project';

    public function handle(): int
    {
        // One UPDATE ... FROM rather than a row per project: this is thousands
        // of projects on a large tenant, and the aggregate is the cheap part.
        //
        // A project with nothing countable — no work items, or only cancelled
        // ones — is left at 0 and given a timestamp anyway. The column is
        // NOT NULL with a 0..100 check, so "no work yet" cannot be expressed
        // here; `progress_cached_at` says the figure is current, and the
        // directory tells those two cases apart by the open-work count it
        // already carries.
        $updated = DB::update(<<<'SQL'
            UPDATE projects p
               SET progress_cache = coalesce(counted.percent, 0),
                   progress_cached_at = now()
              FROM (
                    SELECT pr.id,
                           CASE
                               WHEN count(w.id) FILTER (
                                        WHERE w.state_category <> 'cancelled'
                                    ) = 0
                               THEN NULL
                               ELSE round(
                                    100.0 * count(w.id) FILTER (
                                        WHERE w.state_category = 'done'
                                    ) / count(w.id) FILTER (
                                        WHERE w.state_category <> 'cancelled'
                                    ), 2)
                           END AS percent
                      FROM projects pr
                 LEFT JOIN work_items w
                        ON w.project_id = pr.id
                       AND w.organization_id = pr.organization_id
                       AND w.deleted_at IS NULL
                     WHERE pr.deleted_at IS NULL
                     GROUP BY pr.id
                   ) AS counted
             WHERE counted.id = p.id
        SQL);

        $this->info("Recomputed progress for {$updated} projects.");

        return self::SUCCESS;
    }
}

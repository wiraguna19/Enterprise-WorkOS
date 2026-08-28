<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\DB;

/**
 * Fractional ordering for boards and lists (docs/03 §3).
 *
 * Dropping a card between two others writes ONE row: the new position is the
 * midpoint of its neighbours. Integer ordering would require renumbering every
 * card below the drop point, which is both slow and a write amplification
 * problem on a busy board.
 */
final class PositionCalculator
{
    private const GAP = 1000.0;

    /**
     * Floating-point midpoints run out of precision after roughly 50 successive
     * drops in the same gap. Past this threshold the column is renumbered once,
     * as a background job — rare, and far cheaper than renumbering every time.
     */
    private const MIN_GAP = 0.0001;

    public function forNewItem(?string $projectId, string $stateCategory): float
    {
        $highest = DB::table('work_items')
            ->where('project_id', $projectId)
            ->where('state_category', $stateCategory)
            ->whereNull('deleted_at')
            ->max('position');

        return (float) $highest + self::GAP;
    }

    /**
     * The position between two neighbours. Either may be null (dropped at the
     * top or the bottom of a column).
     */
    public function between(?string $beforeId, ?string $afterId): float
    {
        $before = $beforeId !== null ? $this->positionOf($beforeId) : null;
        $after = $afterId !== null ? $this->positionOf($afterId) : null;

        if ($before === null && $after === null) {
            return self::GAP;
        }

        if ($before === null) {
            return $after - self::GAP;
        }

        if ($after === null) {
            return $before + self::GAP;
        }

        $midpoint = ($before + $after) / 2;

        if (abs($after - $before) < self::MIN_GAP) {
            // Precision exhausted. Returning the midpoint anyway would silently
            // produce two identical positions and an unstable sort order.
            throw new \RuntimeException(
                'Position precision exhausted between neighbours; this column needs renumbering.'
            );
        }

        return $midpoint;
    }

    private function positionOf(string $workItemId): float
    {
        return (float) WorkItemModel::query()->findOrFail($workItemId)->position;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Platform\Application\Event\RecordsDomainEvents;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Domain\Exception\InvalidTimeEntry;
use App\Modules\Work\Infrastructure\Eloquent\TimeEntryModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\DB;

/**
 * Logging time, and keeping the rollup honest.
 *
 * `work_items.actual_hours_cache` is derived and never authoritative
 * (docs/03 §4). It is recalculated from the entries themselves inside the same
 * transaction as every write — see ADR 0003 for why that is here rather than in
 * a job, and what it would take to move it.
 */
final class TimeEntryService
{
    use RecordsDomainEvents;

    /**
     * A person cannot spend more than a day in a day.
     *
     * The CHECK constraint bounds a single entry at 24 hours; this bounds the
     * DAY, across entries and across work items. Both exist because the entry
     * limit alone lets four 8-hour entries land on one Tuesday, and every
     * capacity number downstream then describes a person who does not exist
     * (docs/02 §11).
     */
    private const MAX_HOURS_PER_DAY = 24.0;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly TenantContext $tenant,
    ) {}

    public function log(
        WorkItemModel $item,
        string $membershipId,
        float $hours,
        string $loggedOn,
        string $note = '',
    ): TimeEntryModel {
        return $this->transactional(function () use ($item, $membershipId, $hours, $loggedOn, $note): TimeEntryModel {
            $this->assertDayHasRoom($membershipId, $loggedOn, $hours);

            $entry = new TimeEntryModel;
            $entry->forceFill([
                'id' => TimeEntryModel::newId(),
                'work_item_id' => $item->getKey(),
                'membership_id' => $membershipId,
                'hours' => $hours,
                'logged_on' => $loggedOn,
                'note' => mb_substr($note, 0, 300),
                'created_at' => now(),
            ])->save();

            $this->refreshRollup((string) $item->getKey());

            $this->activity->record('work_item', (string) $item->getKey(), 'time_logged', [
                'hours' => ['from' => null, 'to' => $hours],
                'logged_on' => ['from' => null, 'to' => $loggedOn],
            ]);

            return $entry;
        });
    }

    /**
     * Entries are DELETED, not closed — the one place in this codebase where
     * that is right.
     *
     * Everything else here keeps history because the history is the record
     * (assignments, transitions, decisions). A time entry is not a record of
     * something that happened to the work; it is a claim about hours, and a
     * mistyped claim has no story worth preserving. Keeping tombstones would
     * only make the rollup harder to explain.
     */
    public function delete(TimeEntryModel $entry): void
    {
        $this->transactional(function () use ($entry): void {
            $workItemId = (string) $entry->work_item_id;
            $hours = (float) $entry->hours;

            $entry->delete();

            $this->refreshRollup($workItemId);

            $this->activity->record('work_item', $workItemId, 'time_removed', [
                'hours' => ['from' => $hours, 'to' => null],
            ]);
        });
    }

    /**
     * The cache is the sum, always — never the old value plus a delta.
     *
     * Incremental arithmetic is how a derived number drifts: one failed
     * transaction, one concurrent delete, and the total is wrong with nothing
     * to point at. Recomputing is a single indexed aggregate.
     */
    private function refreshRollup(string $workItemId): void
    {
        $total = (float) DB::table('time_entries')
            ->where('work_item_id', $workItemId)
            ->sum('hours');

        DB::table('work_items')
            ->where('id', $workItemId)
            ->update(['actual_hours_cache' => $total]);
    }

    private function assertDayHasRoom(string $membershipId, string $loggedOn, float $hours): void
    {
        $already = (float) DB::table('time_entries')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('membership_id', $membershipId)
            ->where('logged_on', $loggedOn)
            ->sum('hours');

        if ($already + $hours > self::MAX_HOURS_PER_DAY) {
            throw new InvalidTimeEntry(
                'That would put more than 24 hours on one day.',
                [
                    'logged_on' => $loggedOn,
                    'already_logged' => $already,
                    'requested' => $hours,
                ],
            );
        }
    }
}

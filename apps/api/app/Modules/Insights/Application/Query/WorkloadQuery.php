<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Query;

use App\Modules\Governance\Application\Service\SettingResolver;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Domain\Work\StateCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Workload, computed exactly as docs/02 §11 defines it.
 *
 * That section exists because a workload bar is a number a manager makes
 * staffing decisions from, and a vague definition is worse than none. This
 * class is the definition in code, and the response carries the same figures
 * the formula names — capacity, committed, and the two honesty flags — so the
 * UI can show its working rather than a bar.
 *
 *     capacity(person, week)  = weekly_capacity_hours (per person, part-time
 *                               respected) − approved time off that week
 *     committed(person, week) = Σ estimate_hours over items where the person is
 *                               the active assignee, the state category is
 *                               todo / in_progress / in_review, and the item's
 *                               date range overlaps the week — the estimate
 *                               spread across the item's span, and an item with
 *                               no estimate counted at the organization's
 *                               default_estimate_hours
 *     utilization             = committed / capacity
 *
 * Two deliberate departures, both surfaced rather than hidden:
 *
 * 1. **Time off is not subtracted, because there is nowhere to read it from.**
 *    No time-off table exists in the schema. Silently treating everyone as
 *    fully available would make a person on leave look under-committed, which
 *    is the exact staffing mistake this number exists to prevent — so the
 *    response says `time_off_hours: null` rather than 0, and the UI is expected
 *    to say the capacity is unadjusted.
 *
 * 2. **Items with no dates cannot overlap a week**, so they are excluded from
 *    the total and counted separately. Same rule as an unestimated item: shown
 *    as such, never silently zero.
 *
 * This is operational capacity signal, NOT a performance score (docs/02 §11).
 * Nothing here ranks people, and nothing here should grow a method that does.
 */
final class WorkloadQuery
{
    /** Committed work is work someone has agreed to do and not yet finished. */
    private const COMMITTED_CATEGORIES = StateCategory::COMMITTED;

    public function __construct(
        private readonly SettingResolver $settings,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forMembership(string $membershipId, CarbonImmutable $weekStart): array
    {
        $weekEnd = $weekStart->addDays(6);

        $capacity = (float) (DB::table('employee_profiles')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('membership_id', $membershipId)
            ->value('weekly_capacity_hours') ?? 40.0);

        $defaultEstimate = (float) $this->settings->get('work.default_estimate_hours', 4);

        $items = $this->committedItems($membershipId);

        $committed = 0.0;
        $unestimated = 0;
        $undated = 0;
        $counted = 0;

        foreach ($items as $item) {
            $estimate = $item->estimateHours ?? $defaultEstimate;

            if ($item->estimateHours === null) {
                $unestimated++;
            }

            $share = $this->shareFallingIn($item, $weekStart, $weekEnd, $estimate);

            if ($share === null) {
                $undated++;

                continue;
            }

            if ($share > 0.0) {
                $committed += $share;
                $counted++;
            }
        }

        return [
            'membership_id' => $membershipId,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),

            'capacity_hours' => round($capacity, 2),
            // Null, not zero: nothing in the schema records leave, and zero
            // would be a claim rather than an absence.
            'time_off_hours' => null,

            'committed_hours' => round($committed, 2),
            'utilization' => $capacity > 0 ? round($committed / $capacity, 4) : null,

            'item_count' => $counted,
            // A bar built on unestimated work is a lie, so the count travels
            // with the number and the UI says so (docs/02 §11).
            'unestimated_count' => $unestimated,
            // Committed work that cannot be placed in any week. Not in the
            // total, and not invisible either.
            'undated_count' => $undated,
            'default_estimate_hours' => $defaultEstimate,
        ];
    }

    /**
     * The share of an item's estimate that falls inside this week.
     *
     * Spread evenly across the item's span in DAYS, which is the simplest rule
     * that is defensible: a two-week item with a 20-hour estimate is 10 hours of
     * commitment in each of them, not 20 in whichever week you happen to be
     * looking at. Even spreading is a model, not a measurement — it does not
     * know that work bunches near a deadline — and docs/02 §11 chose it
     * knowingly over a curve nobody could explain to the person being counted.
     *
     * Returns null when the item has no dates at all, which is different from
     * returning zero: zero means "none of it lands here", null means "this
     * cannot be placed".
     */
    private function shareFallingIn(
        CommittedItem $item,
        CarbonImmutable $weekStart,
        CarbonImmutable $weekEnd,
        float $estimate,
    ): ?float {
        $start = $item->startDate === null ? null : CarbonImmutable::parse($item->startDate);
        $due = $item->dueAt === null ? null : CarbonImmutable::parse($item->dueAt);

        if ($start === null && $due === null) {
            return null;
        }

        // A single date is a one-day span: the deadline is when the work is
        // due, and with nothing else known that is where it is counted.
        $from = ($start ?? $due)->startOfDay();
        $to = ($due ?? $start)->startOfDay();

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        $spanDays = $from->diffInDays($to) + 1;

        $overlapFrom = $from->greaterThan($weekStart) ? $from : $weekStart;
        $overlapTo = $to->lessThan($weekEnd) ? $to : $weekEnd;

        if ($overlapTo->lessThan($overlapFrom)) {
            return 0.0;
        }

        $overlapDays = $overlapFrom->diffInDays($overlapTo) + 1;

        return $estimate * ($overlapDays / $spanDays);
    }

    /**
     * Every open item this person is the assignee of.
     *
     * Deliberately NOT filtered by the caller's visibility. Capacity is a fact
     * about a person's week; a total that changed depending on who asked would
     * be useless for the decision it exists to inform — two managers would see
     * two different numbers for the same colleague. What the caller may READ is
     * a separate question, answered where the items themselves are listed.
     *
     * @return list<CommittedItem>
     */
    private function committedItems(string $membershipId): array
    {
        $rows = DB::table('work_items')
            ->select(['id', 'estimate_hours', 'start_date', 'due_at'])
            ->where('organization_id', $this->tenant->organizationId())
            ->whereNull('deleted_at')
            ->whereIn('state_category', self::COMMITTED_CATEGORIES)
            ->whereExists(fn ($sub) => $sub
                ->from('work_item_assignments')
                ->whereColumn('work_item_assignments.work_item_id', 'work_items.id')
                ->where('work_item_assignments.membership_id', $membershipId)
                ->where('work_item_assignments.role', 'assignee')
                ->whereNull('work_item_assignments.unassigned_at'))
            ->get();

        return array_values($rows->map(static fn (object $row): CommittedItem => new CommittedItem(
            id: (string) $row->id,
            estimateHours: $row->estimate_hours === null ? null : (float) $row->estimate_hours,
            startDate: $row->start_date === null ? null : (string) $row->start_date,
            dueAt: $row->due_at === null ? null : (string) $row->due_at,
        ))->all());
    }
}

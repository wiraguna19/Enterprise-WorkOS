<?php

declare(strict_types=1);

namespace App\Modules\Leave\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * How many days a stretch of time off is, under this organization's rules.
 *
 * Working days skip the weekdays the policy does not work and the holidays;
 * calendar days count everything. A half day is half of one working day.
 */
final class LeaveCalendar
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param list<int> $workingDays ISO weekdays, 1 = Monday */
    public function count(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $basis,
        array $workingDays,
        bool $halfDay = false,
    ): float {
        if ($basis === 'calendar_days') {
            return (float) ($from->diffInDays($to) + 1);
        }

        $holidays = DB::table('leave_holidays')
            ->where('organization_id', $this->tenant->organizationId())
            ->whereBetween('on_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('on_date')
            ->map(static fn (mixed $date): string => substr((string) $date, 0, 10))
            ->all();

        $days = 0;

        foreach (CarbonPeriod::create($from, $to) as $day) {
            if (in_array($day->isoWeekday(), $workingDays, strict: true)
                && ! in_array($day->toDateString(), $holidays, strict: true)) {
                $days++;
            }
        }

        return $halfDay ? min(0.5, $days / 2) : (float) $days;
    }
}

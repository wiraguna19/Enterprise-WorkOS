<?php

declare(strict_types=1);

namespace App\Modules\Leave\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * How much annual leave somebody has, on a given day, under this
 * organization's rules (ADR 0063).
 *
 * Computed, never stored: a stored balance is a second copy of the requests
 * that eventually disagrees with them. The steps, in order:
 *
 * 1. **The period** the day falls in: the calendar year, or the year since
 *    the person's last hire anniversary.
 * 2. **The entitlement** for that period: base days, plus the highest tenure
 *    band reached by the start of the period, plus the bonus for their level.
 * 3. **What is earned so far**: all of it (upfront), a twelfth per month
 *    started (monthly), or monthly only in the period the person was hired
 *    in and all of it afterwards (monthly_first_year).
 * 4. **What was carried** from the previous period: what was left of it, up
 *    to the policy's maximum, usable until the end of the policy's month.
 *    Days taken are drawn from the carried days first. The previous period's
 *    own carry is not chained further back: one period of carry, as every
 *    policy the product has been shown uses.
 * 5. **What is taken**: approved requests of quota types starting in the
 *    period, and — separately — pending ones, which are reserved so that two
 *    requests cannot both spend the same days.
 */
final class LeaveBalance
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly LeaveSettings $settings,
    ) {}

    /** @return array<string, mixed>|null null while the organization has set no leave rules */
    public function for(string $membershipId, CarbonImmutable $at): ?array
    {
        $policy = $this->settings->policy();

        if ($policy === null) {
            return null;
        }

        $person = $this->person($membershipId);
        $hired = $person['hired'] ?? $at;

        [$start, $end] = $this->period($policy, $hired, $at);

        $entitlement = $this->entitlement($policy, $hired, $start, $person['level']);
        $earned = round($entitlement * $this->fraction($policy, $hired, $start, $at), 1);

        // The previous period, if the person was here for any of it.
        $carried = 0.0;
        $carryExpires = null;

        if ((float) $policy['carry_over_max_days'] > 0 && $start->greaterThan($hired)) {
            [$previousStart, $previousEnd] = $this->period($policy, $hired, $start->subDay());
            $previousEntitlement = $this->entitlement($policy, $hired, $previousStart, $person['level']);
            $previousEarned = round($previousEntitlement * $this->fraction($policy, $hired, $previousStart, $previousEnd), 1);
            $previousUsed = $this->taken($membershipId, $previousStart, $previousEnd, ['approved']);

            $carried = min((float) $policy['carry_over_max_days'], max(0.0, $previousEarned - $previousUsed));
            $carryExpires = $this->carryExpiry($policy, $start, $end);
        }

        $used = $this->taken($membershipId, $start, $end, ['approved']);
        $pending = $this->taken($membershipId, $start, $end, ['pending']);

        // Carried days not spent by their expiry are gone; spending draws on
        // them first, so what counts is what was taken up to that day.
        $carriedUsable = $carried;

        if ($carried > 0 && $carryExpires !== null && $at->greaterThan($carryExpires)) {
            $carriedUsable = min($carried, $this->taken($membershipId, $start, $carryExpires, ['approved', 'pending']));
        }

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'entitlement' => $entitlement,
            'earned' => $earned,
            'carried' => $carried,
            'carry_expires_on' => $carryExpires?->toDateString(),
            'used' => $used,
            'pending' => $pending,
            'available' => round($earned + $carriedUsable - $used - $pending, 1),
            'probation_ends_on' => $hired->addMonths((int) $policy['probation_months'])->toDateString(),
            'hired_on' => $person['hired']?->toDateString(),
        ];
    }

    /** @return array{hired: CarbonImmutable|null, level: string|null} */
    public function person(string $membershipId): array
    {
        /** @var stdClass|null $row */
        $row = DB::table('memberships as m')
            ->leftJoin('employee_profiles as p', 'p.membership_id', '=', 'm.id')
            ->where('m.organization_id', $this->tenant->organizationId())
            ->where('m.id', $membershipId)
            ->first(['p.hired_at', 'p.job_level', 'm.joined_at']);

        $hired = $row === null ? null : ($row->hired_at ?? $row->joined_at);

        return [
            'hired' => $hired === null ? null : CarbonImmutable::parse((string) $hired)->startOfDay(),
            'level' => $row?->job_level === null ? null : (string) $row->job_level,
        ];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(array $policy, CarbonImmutable $hired, CarbonImmutable $at): array
    {
        if ($policy['period'] === 'hire_anniversary') {
            $years = max(0, (int) floor($hired->diffInYears($at)));
            $start = $hired->addYears($years);

            return [$start, $start->addYear()->subDay()];
        }

        return [$at->startOfYear()->startOfDay(), $at->endOfYear()->startOfDay()];
    }

    /** @param array<string, mixed> $policy */
    private function entitlement(array $policy, CarbonImmutable $hired, CarbonImmutable $periodStart, ?string $level): float
    {
        $tenureYears = (int) floor($hired->diffInYears($periodStart->max($hired)));
        $bonus = 0.0;

        /** @var list<array{years: int, days: float}> $bands */
        $bands = $policy['tenure_bonus'];

        foreach ($bands as $band) {
            if ($tenureYears >= $band['years']) {
                $bonus = max($bonus, (float) $band['days']);
            }
        }

        $levels = (array) $policy['level_bonus'];
        $levelBonus = $level !== null && isset($levels[$level]) ? (float) $levels[$level] : 0.0;

        return (float) $policy['base_days'] + $bonus + $levelBonus;
    }

    /** @param array<string, mixed> $policy */
    private function fraction(array $policy, CarbonImmutable $hired, CarbonImmutable $periodStart, CarbonImmutable $at): float
    {
        if ($at->lessThan($hired)) {
            return 0.0;
        }

        $monthly = match ($policy['accrual']) {
            'upfront' => false,
            'monthly' => true,
            // Monthly only in the period the person joined in.
            default => $hired->greaterThanOrEqualTo($periodStart),
        };

        if (! $monthly) {
            return 1.0;
        }

        // Earned at the start of each month: a person on their first day has
        // their first twelfth.
        $from = $periodStart->max($hired);
        $months = (int) floor($from->diffInMonths($at)) + 1;

        return min(1.0, $months / 12);
    }

    /** @param array<string, mixed> $policy */
    private function carryExpiry(array $policy, CarbonImmutable $start, CarbonImmutable $end): CarbonImmutable
    {
        $month = $policy['carry_over_until_month'];

        if ($month === null) {
            return $end;
        }

        $expiry = $start->setDate($start->year, (int) $month, 1)->endOfMonth()->startOfDay();

        return $expiry->lessThan($start) ? $expiry->addYear()->endOfMonth()->startOfDay() : $expiry;
    }

    /** @param list<string> $statuses */
    private function taken(string $membershipId, CarbonImmutable $from, CarbonImmutable $to, array $statuses): float
    {
        return (float) DB::table('leave_requests as r')
            ->join('leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->where('r.organization_id', $this->tenant->organizationId())
            ->where('r.membership_id', $membershipId)
            ->where('t.uses_quota', true)
            ->whereIn('r.status', $statuses)
            ->whereBetween('r.starts_on', [$from->toDateString(), $to->toDateString()])
            ->sum('r.days');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Leave\Domain;

/**
 * A starting point, not a rule (ADR 0063).
 *
 * A preset fills the policy and the leave types with one country's usual
 * minimums. Everything it writes stays editable: the product is used by
 * companies with their own rules, and the law is theirs to apply. What a
 * preset adds beyond the starting values is its MINIMUMS — the settings page
 * warns when the organization goes below them, and refuses nothing.
 *
 * Indonesia, as far as this was written in October 2026: twelve working days
 * of annual leave after twelve months' service; maternity leave of three
 * months; paid leave for a father at the birth; sick leave not counted
 * against the annual quota. Check current law before relying on it.
 */
final class LeavePreset
{
    public const KEYS = ['indonesia'];

    /**
     * @return array{period: string, accrual: string, base_days: float, probation_months: int, carry_over_max_days: float, carry_over_until_month: int|null, approval: string, working_days: list<int>, tenure_bonus: list<array{years: int, days: float}>, level_bonus: array<string, float>}
     */
    public static function policy(string $preset): array
    {
        return [
            'period' => 'calendar_year',
            // Earned a day a month in the first year, the whole quota after:
            // somebody who joined in March is not locked out until next March.
            'accrual' => 'monthly_first_year',
            'base_days' => 12.0,
            'probation_months' => 3,
            'carry_over_max_days' => 5.0,
            'carry_over_until_month' => 6,
            'approval' => 'manager',
            'working_days' => [1, 2, 3, 4, 5],
            'tenure_bonus' => [],
            'level_bonus' => [],
        ];
    }

    /**
     * @return list<array{key: string, name: string, paid: bool, uses_quota: bool, after_probation: bool, day_basis: string, max_days_per_request: int|null, attachment_after_days: int|null, allow_half_day: bool, sort_order: int}>
     */
    public static function types(string $preset): array
    {
        return [
            ['key' => 'annual', 'name' => 'Cuti tahunan', 'paid' => true, 'uses_quota' => true, 'after_probation' => true,
                'day_basis' => 'working_days', 'max_days_per_request' => null, 'attachment_after_days' => null, 'allow_half_day' => true, 'sort_order' => 10],
            ['key' => 'sick', 'name' => 'Sakit', 'paid' => true, 'uses_quota' => false, 'after_probation' => false,
                'day_basis' => 'working_days', 'max_days_per_request' => null, 'attachment_after_days' => 2, 'allow_half_day' => true, 'sort_order' => 20],
            ['key' => 'personal', 'name' => 'Izin', 'paid' => false, 'uses_quota' => false, 'after_probation' => false,
                'day_basis' => 'working_days', 'max_days_per_request' => null, 'attachment_after_days' => null, 'allow_half_day' => true, 'sort_order' => 30],
            ['key' => 'maternity', 'name' => 'Cuti melahirkan', 'paid' => true, 'uses_quota' => false, 'after_probation' => false,
                'day_basis' => 'calendar_days', 'max_days_per_request' => 90, 'attachment_after_days' => 0, 'allow_half_day' => false, 'sort_order' => 40],
            ['key' => 'paternity', 'name' => 'Cuti ayah', 'paid' => true, 'uses_quota' => false, 'after_probation' => false,
                'day_basis' => 'working_days', 'max_days_per_request' => 2, 'attachment_after_days' => null, 'allow_half_day' => false, 'sort_order' => 50],
        ];
    }

    /**
     * The settings a preset warns about when they go below it.
     *
     * @return array{base_days: float, types: array<string, int>}
     */
    public static function minimums(string $preset): array
    {
        return [
            'base_days' => 12.0,
            // Per type key: the fewest days a single request must be able to
            // cover, and the type must exist and be active.
            'types' => ['maternity' => 90, 'paternity' => 2, 'sick' => 0],
        ];
    }
}

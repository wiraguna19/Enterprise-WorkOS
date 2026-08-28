<?php

declare(strict_types=1);

/**
 * Capacity is the denominator of every workload bar a manager will make
 * staffing decisions from. A wrong denominator is worse than no bar at all
 * (docs/02 §11), so it is unit tested before any UI reads it.
 */

use App\Modules\Organization\Infrastructure\Eloquent\EmployeeProfileModel;
use Illuminate\Database\QueryException;

it('reads capacity per person rather than assuming 40 hours', function (): void {
    $seeded = actingWithinTenant('01900000-0000-7000-8000-0000000000ac', fn () => EmployeeProfileModel::query()
        ->pluck('weekly_capacity_hours', 'job_title'));

    expect((float) $seeded['Product Designer'])->toBe(24.0)
        ->and((float) $seeded['External Consultant'])->toBe(16.0)
        ->and((float) $seeded['Backend Developer'])->toBe(40.0);
});

it('rejects a capacity the database considers impossible', function (): void {
    // The CHECK constraint is the real guard; this asserts it is still there.
    expect(fn () => actingWithinTenant('01900000-0000-7000-8000-0000000000ac', function (): void {
        $profile = EmployeeProfileModel::query()->firstOrFail();
        $profile->forceFill(['weekly_capacity_hours' => 200])->save();
    }))->toThrow(QueryException::class);
});

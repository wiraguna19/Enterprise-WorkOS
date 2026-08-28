<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $membership_id
 * @property string|null $employee_number
 * @property string $job_title
 * @property string|null $department_id
 * @property string|null $manager_profile_id
 * @property string $employment_type
 * @property string $weekly_capacity_hours
 * @property CarbonImmutable|null $hired_at
 * @property string $work_location
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class EmployeeProfileModel extends TenantModel
{
    protected $table = 'employee_profiles';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weekly_capacity_hours' => 'decimal:2',
            'hired_at' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'membership_id');
    }

    /** @return BelongsTo<DepartmentModel, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(DepartmentModel::class, 'department_id');
    }

    /** @return BelongsTo<EmployeeProfileModel, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_profile_id');
    }

    /** @return HasMany<EmployeeProfileModel, $this> */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_profile_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $department_id
 * @property string $name
 * @property string $key
 * @property string $description
 * @property string|null $lead_membership_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $archived_at
 */
final class TeamModel extends TenantModel
{
    protected $table = 'teams';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DepartmentModel, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(DepartmentModel::class, 'department_id');
    }

    /** @return HasMany<TeamMemberModel, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(TeamMemberModel::class, 'team_id')->whereNull('left_at');
    }

    /**
     * Including people who have since left — needed to read historical work.
     *
     * @return HasMany<TeamMemberModel, $this>
     */
    public function allMembersEver(): HasMany
    {
        return $this->hasMany(TeamMemberModel::class, 'team_id');
    }

    /** @param Builder<TeamModel> $query
     *  @return Builder<TeamModel> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}

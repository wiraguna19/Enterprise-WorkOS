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
 * @property string|null $parent_id
 * @property string $name
 * @property string $code
 * @property string|null $head_membership_id
 * @property string $path
 * @property int $depth
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $archived_at
 */
final class DepartmentModel extends TenantModel
{
    protected $table = 'departments';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['depth' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DepartmentModel, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<DepartmentModel, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<TeamModel, $this> */
    public function teams(): HasMany
    {
        return $this->hasMany(TeamModel::class, 'department_id');
    }

    /**
     * Everything at or below this department, as one index scan on the
     * materialized path — not a recursive CTE (docs/03 §2).
     */
    /** @param Builder<DepartmentModel> $query
     *  @return Builder<DepartmentModel> */
    public function scopeUnder(Builder $query, self $department): Builder
    {
        return $query->where('path', 'like', $department->path.'%');
    }

    /** @param Builder<DepartmentModel> $query
     *  @return Builder<DepartmentModel> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}

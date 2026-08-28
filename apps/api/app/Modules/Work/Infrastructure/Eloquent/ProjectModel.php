<?php

declare(strict_types=1);

namespace App\Modules\Work\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Organization\Infrastructure\Eloquent\DepartmentModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A container for work, not a work item (docs/02 §5).
 *
 * Also a permission boundary: project membership is one of the ways a person
 * can see a work item at all (docs/06 §2).
 *
 * @property-read int|null $open_work_count      only when the directory query selects it
 * @property-read int|null $overdue_work_count   only when the directory query selects it
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 * @property string $id
 * @property string $organization_id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property string|null $owner_membership_id
 * @property string|null $department_id
 * @property string|null $workflow_id
 * @property string $status
 * @property string $priority
 * @property string $visibility
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $end_date
 * @property string|null $budget_amount
 * @property string|null $budget_currency
 * @property string $progress_cache
 * @property CarbonImmutable|null $progress_cached_at
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $deleted_at
 */
final class ProjectModel extends TenantModel
{
    use SoftDeletes;

    protected $table = 'projects';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'budget_amount' => 'decimal:2',
            'progress_cache' => 'decimal:2',
            'progress_cached_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'owner_membership_id');
    }

    /** @return BelongsTo<DepartmentModel, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(DepartmentModel::class, 'department_id');
    }

    /** @return HasMany<ProjectMemberModel, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMemberModel::class, 'project_id')->whereNull('removed_at');
    }

    /** @return HasMany<MilestoneModel, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(MilestoneModel::class, 'project_id')->orderBy('position');
    }

    /** @return HasMany<WorkItemModel, $this> */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItemModel::class, 'project_id');
    }

    /** @param Builder<ProjectModel> $query
     *  @return Builder<ProjectModel> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Projects this person may see.
     *
     * Expressed as a query scope rather than a post-fetch filter, so it is
     * impossible to page over rows the caller cannot see. `internal` projects
     * are visible to anyone with project.view_all; `private` ones require
     * membership regardless of permission.
     */
    /** @param Builder<ProjectModel> $query
     *  @return Builder<ProjectModel> */
    public function scopeVisibleTo(Builder $query, string $membershipId, bool $canViewAll): Builder
    {
        return $query->where(function (Builder $q) use ($membershipId, $canViewAll): void {
            if ($canViewAll) {
                $q->where('visibility', 'internal');
            }

            $q->orWhere('owner_membership_id', $membershipId)
                ->orWhereExists(
                    fn ($sub) => $sub
                        ->from('project_members')
                        ->whereColumn('project_members.project_id', 'projects.id')
                        ->whereNull('removed_at')
                        ->where(fn ($w) => $w
                            ->where('membership_id', $membershipId)
                            ->orWhereIn('team_id', fn ($teams) => $teams
                                ->from('team_members')
                                ->select('team_id')
                                ->where('membership_id', $membershipId)
                                ->whereNull('left_at')))
                );
        });
    }
}

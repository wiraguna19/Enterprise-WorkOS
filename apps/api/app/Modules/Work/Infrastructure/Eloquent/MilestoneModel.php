<?php

declare(strict_types=1);

namespace App\Modules\Work\Infrastructure\Eloquent;

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
 * @property string $project_id
 * @property string $name
 * @property string $description
 * @property CarbonImmutable|null $due_date
 * @property string $status
 * @property int $position
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MilestoneModel extends TenantModel
{
    protected $table = 'milestones';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_date' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<ProjectModel, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ProjectModel::class, 'project_id');
    }

    /** @return HasMany<WorkItemModel, $this> */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItemModel::class, 'milestone_id');
    }
}

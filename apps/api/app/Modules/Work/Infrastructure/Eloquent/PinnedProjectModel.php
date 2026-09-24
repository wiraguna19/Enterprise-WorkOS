<?php

declare(strict_types=1);

namespace App\Modules\Work\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One project somebody keeps in their own sidebar (ADR 0044).
 *
 * Per MEMBERSHIP, not per user: somebody in two organizations pins different
 * things in each.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $membership_id
 * @property string $project_id
 * @property int $position
 * @property CarbonImmutable $created_at
 */
final class PinnedProjectModel extends TenantModel
{
    protected $table = 'pinned_projects';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProjectModel, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ProjectModel::class, 'project_id');
    }
}

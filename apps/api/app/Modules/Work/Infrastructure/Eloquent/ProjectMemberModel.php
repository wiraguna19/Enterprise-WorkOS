<?php

declare(strict_types=1);

namespace App\Modules\Work\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Organization\Infrastructure\Eloquent\TeamModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grants project access to a person OR a whole team — never both, enforced by
 * a CHECK constraint. Team-based membership is what stops a project's member
 * list from being a manual copy of a team roster that immediately goes stale.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string|null $membership_id
 * @property string|null $team_id
 * @property string $role
 * @property string|null $added_by
 * @property CarbonImmutable $added_at
 * @property CarbonImmutable|null $removed_at
 */
final class ProjectMemberModel extends TenantModel
{
    protected $table = 'project_members';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'added_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'membership_id');
    }

    /** @return BelongsTo<TeamModel, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(TeamModel::class, 'team_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Closed, never deleted (left_at). Team composition history is what makes past
 * workload and past work interpretable.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $team_id
 * @property string $membership_id
 * @property string $role
 * @property CarbonImmutable $joined_at
 * @property CarbonImmutable|null $left_at
 */
final class TeamMemberModel extends TenantModel
{
    protected $table = 'team_members';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TeamModel, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(TeamModel::class, 'team_id');
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'membership_id');
    }
}

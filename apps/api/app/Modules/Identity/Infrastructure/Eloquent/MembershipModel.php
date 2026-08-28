<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A user's belonging to one organization. Everything tenant-scoped that refers
 * to a person refers to a membership, never directly to a user — that is what
 * keeps one human's identity separable from their role in each company.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $status
 * @property CarbonImmutable|null $invited_at
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *                                       `employeeProfile` is NOT declared here. The profile is Organization's, and
 *                                       Identity may not depend on Organization — the arrow runs the other way, since
 *                                       people belong to organizations (docs/04 §3). Organization contributes the
 *                                       relation to this model at boot via resolveRelationUsing(), so every existing
 *                                       `->with('employeeProfile')` keeps working while the dependency points the way
 *                                       the module graph says it must.
 */
final class MembershipModel extends TenantModel
{
    protected $table = 'memberships';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'invited_at' => 'immutable_datetime',
            'joined_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<UserModel, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    /** @return BelongsToMany<RoleModel, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(RoleModel::class, 'membership_roles', 'membership_id', 'role_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->revoked_at === null;
    }
}

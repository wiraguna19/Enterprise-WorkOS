<?php

declare(strict_types=1);

namespace App\Modules\Work\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assignment is an entity, not a pivot (docs/02 §6).
 *
 * There is deliberately no delete path on this model. Removing someone closes
 * the row; the history is the product feature.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $work_item_id
 * @property string $membership_id
 * @property string $role
 * @property bool $is_primary
 * @property string|null $assigned_by_membership_id
 * @property CarbonImmutable $assigned_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $unassigned_at
 * @property string|null $unassigned_reason
 */
final class WorkItemAssignmentModel extends TenantModel
{
    protected $table = 'work_item_assignments';

    public $timestamps = false;

    public const ROLES = ['assignee', 'reviewer', 'approver', 'watcher', 'collaborator'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'assigned_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'unassigned_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkItemModel, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItemModel::class, 'work_item_id');
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'membership_id');
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'assigned_by_membership_id');
    }

    /** @param Builder<WorkItemAssignmentModel> $query
     *  @return Builder<WorkItemAssignmentModel> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('unassigned_at');
    }

    public function isActive(): bool
    {
        return $this->unassigned_at === null;
    }

    /**
     * Assigned but not acknowledged.
     *
     * A distinct state from "in progress", and one managers need to see: work
     * nobody has picked up looks identical to work in flight on a status board.
     */
    public function isAwaitingAcceptance(): bool
    {
        return $this->isActive() && $this->accepted_at === null && $this->role === 'assignee';
    }
}

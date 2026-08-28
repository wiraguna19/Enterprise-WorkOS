<?php

declare(strict_types=1);

namespace App\Modules\Approval\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
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
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $requested_by_membership_id
 * @property string $status
 * @property string $policy
 * @property int $required_approvals
 * @property string $submission_note
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $due_at
 * @property int $lock_version
 */
final class ApprovalModel extends TenantModel
{
    protected $table = 'approvals';

    public $timestamps = false;

    public const POLICIES = ['any_one', 'all_of', 'quorum'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'required_approvals' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'requested_by_membership_id');
    }

    /** @return HasMany<ApprovalApproverModel, $this> */
    public function approvers(): HasMany
    {
        return $this->hasMany(ApprovalApproverModel::class, 'approval_id');
    }

    /**
     * Decisions, oldest first. Never filtered: a changed decision must show
     * BOTH records, or the trail pretends the first one never happened.
     *
     * @return HasMany<ApprovalDecisionModel, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecisionModel::class, 'approval_id')->orderBy('decided_at');
    }

    /** @param Builder<ApprovalModel> $query
     *  @return Builder<ApprovalModel> */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** How far through a quorum this approval is, for the review screen. */
    public function approvalsSoFar(): int
    {
        return $this->decisions
            ->where('decision', 'approved')
            ->unique('reviewer_membership_id')
            ->count();
    }
}

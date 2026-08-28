<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Policy;

use App\Modules\Approval\Infrastructure\Eloquent\ApprovalModel;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class ApprovalPolicy
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
    ) {}

    public function view(UserModel $user, ApprovalModel $approval): bool
    {
        return $this->isParticipant($approval) || $this->can('approval.decide_any');
    }

    /**
     * Two distinct routes to deciding, and the distinction is the point:
     * being ASKED to review this one, or holding the org-wide override that
     * lets an admin unblock a review whose reviewer has left (docs/06 §2).
     */
    public function decide(UserModel $user, ApprovalModel $approval): bool
    {
        if ($this->can('approval.decide_any')) {
            return true;
        }

        $isApprover = DB::table('approval_approvers')
            ->where('approval_id', $approval->getKey())
            ->where('membership_id', $this->tenant->membershipId())
            ->exists();

        return $isApprover && $this->can('approval.decide');
    }

    /** Only the requester withdraws — it is their submission to retract. */
    public function withdraw(UserModel $user, ApprovalModel $approval): bool
    {
        return (string) $approval->requested_by_membership_id === $this->tenant->membershipId()
            && $this->can('approval.withdraw');
    }

    private function isParticipant(ApprovalModel $approval): bool
    {
        $membershipId = $this->tenant->membershipId();

        if ((string) $approval->requested_by_membership_id === $membershipId) {
            return true;
        }

        return DB::table('approval_approvers')
            ->where('approval_id', $approval->getKey())
            ->where('membership_id', $membershipId)
            ->exists();
    }

    private function can(string $permission): bool
    {
        $actor = MembershipModel::query()->find($this->tenant->membershipId());

        return $actor !== null && $this->permissions->has($actor, $permission);
    }
}

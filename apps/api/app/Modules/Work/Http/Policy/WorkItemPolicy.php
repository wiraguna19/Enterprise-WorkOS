<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Policy;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;

/**
 * Per-record authorization — layer 4 of the five in docs/06 §2.
 *
 * Visibility (can I see it at all) is handled in the query by
 * WorkItemVisibility. This class answers "what may I DO to a record I can
 * already see", which is a different question and deliberately a different
 * mechanism: mixing them produces policies that need rows they do not have.
 */
final class WorkItemPolicy
{
    /**
     * Involvement is deliberately never cached: it changes the moment someone
     * is assigned, and a stale `true` here would be an authorization bug. The
     * acting membership, which every check needs, comes from ActingMembership —
     * one row, one query, per request.
     */
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
    ) {}

    public function view(UserModel $user, WorkItemModel $item): bool
    {
        // Reaching this point means the visibility scope already admitted the
        // row. Re-deriving it here would duplicate the rule and let the two
        // copies drift.
        return $this->can('work_item.view');
    }

    public function update(UserModel $user, WorkItemModel $item): bool
    {
        if ($item->isClosed() && ! $this->can('work_item.transition_any')) {
            return false;
        }

        // People may always edit work they hold or created; editing someone
        // else's requires the permission.
        return $this->isInvolved($item) || $this->can('work_item.update');
    }

    public function delete(UserModel $user, WorkItemModel $item): bool
    {
        return $this->can('work_item.delete');
    }

    public function assign(UserModel $user, WorkItemModel $item): bool
    {
        return $this->can('work_item.assign');
    }

    public function transition(UserModel $user, WorkItemModel $item): bool
    {
        return $this->isInvolved($item) || $this->can('work_item.transition');
    }

    /**
     * Closing work over open blockers.
     *
     * Separate from `transition` because "can move work forward" and "can
     * ignore the rules that guard it" are different trust levels.
     */
    public function overrideTransition(UserModel $user, WorkItemModel $item): bool
    {
        return $this->can('work_item.transition_any');
    }

    public function submit(UserModel $user, WorkItemModel $item): bool
    {
        return $this->isInvolved($item) && $this->can('work_item.submit');
    }

    public function comment(UserModel $user, WorkItemModel $item): bool
    {
        return $this->can('comment.create');
    }

    /**
     * Logging time is the org-wide ability and nothing more.
     *
     * Deliberately NOT restricted to people involved in the item: helping with
     * something you are not assigned to is the normal case, and hours that
     * cannot be recorded where they were spent get recorded somewhere worse.
     * Logging time in SOMEONE ELSE'S name is the separate act, and it is
     * checked in the controller against `work_item.assign`.
     */
    public function logTime(UserModel $user, WorkItemModel $item): bool
    {
        return $this->can('work_item.log_time');
    }

    /** Currently assigned, previously assigned, or the creator. */
    private function isInvolved(WorkItemModel $item): bool
    {
        $membershipId = $this->tenant->membershipId();

        if ((string) $item->created_by_membership_id === $membershipId) {
            return true;
        }

        // Prefer the already-eager-loaded collection: the list endpoint loads
        // assignments to render them, so asking the database again is a query
        // per row for something already in memory. Same relation, same filter
        // on unassigned_at — this is a shortcut, not a different rule.
        if ($item->relationLoaded('assignments')) {
            return $item->assignments
                ->contains(fn ($assignment) => (string) $assignment->membership_id === $membershipId);
        }

        return $item->assignments()
            ->where('membership_id', $membershipId)
            ->exists();
    }

    private function can(string $permission): bool
    {
        $actor = $this->acting->get();

        return $actor !== null && $this->permissions->has($actor, $permission);
    }
}

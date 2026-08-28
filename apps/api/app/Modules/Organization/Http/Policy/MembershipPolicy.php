<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Policy;

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;

/**
 * Per-record authorization: layer 4 of docs/06 §2.
 *
 * The tenant scope has already made other organizations' memberships
 * unreachable, so nothing here needs to re-check the organization — and
 * re-checking it would suggest the scope is optional.
 */
final class MembershipPolicy
{
    /**
     * The acting membership, looked up once.
     *
     * Every resource echoes three permission decisions, so a directory of 100
     * people asks this 300 times. Held on the policy, which the container keeps
     * for the length of the request and no longer — a cached actor that
     * outlived the request would answer with permissions the person has since
     * lost.
     */
    private ?MembershipModel $actor = null;

    private ?string $actorFor = null;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
    ) {}

    public function view(UserModel $user, MembershipModel $membership): bool
    {
        return $this->can('person.view');
    }

    public function update(UserModel $user, MembershipModel $membership): bool
    {
        // People may always edit their own profile; editing someone else's is a
        // permission.
        return $this->isSelf($membership) || $this->can('person.update');
    }

    public function deactivate(UserModel $user, MembershipModel $membership): bool
    {
        // Deactivating yourself would lock the last admin out of the
        // organization; it is refused regardless of permission.
        return ! $this->isSelf($membership) && $this->can('person.deactivate');
    }

    public function viewWorkload(UserModel $user, MembershipModel $membership): bool
    {
        return $this->isSelf($membership)
            || $this->can('person.view_workload')
            || $this->managesTransitively($membership);
    }

    private function isSelf(MembershipModel $membership): bool
    {
        return (string) $membership->getKey() === $this->tenant->membershipId();
    }

    /**
     * A manager can see the workload of anyone in their reporting line, at any
     * depth, without needing the org-wide permission.
     */
    private function managesTransitively(MembershipModel $membership): bool
    {
        $actorProfileId = $this->actor()?->employeeProfile?->getKey();

        if ($actorProfileId === null) {
            return false;
        }

        $subject = $membership->employeeProfile;
        $guard = 0;

        while ($subject !== null && $guard++ < 10) {
            if ((string) $subject->manager_profile_id === (string) $actorProfileId) {
                return true;
            }

            $subject = $subject->manager;
        }

        return false;
    }

    private function can(string $permission): bool
    {
        $actor = $this->actor();

        return $actor !== null && $this->permissions->has($actor, $permission);
    }

    /**
     * Keyed by membership id, not merely memoized: one request can act as two
     * people — a console command binding a tenant per membership, or a test
     * that logs in twice — and a cache that ignored who it was for would answer
     * the second with the first one's permissions.
     */
    private function actor(): ?MembershipModel
    {
        $membershipId = $this->tenant->membershipId();

        if ($this->actorFor !== $membershipId) {
            $this->actor = MembershipModel::query()->with('employeeProfile')->find($membershipId);
            $this->actorFor = $membershipId;
        }

        return $this->actor;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Policy;

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Organization\Infrastructure\Eloquent\TeamModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;

/**
 * Per-record authorization for teams: layer 4 of docs/06 §2.
 *
 * The tenant scope has already made other organizations' teams unreachable, so
 * nothing here re-checks the organization.
 *
 * This class did not exist until Phase 5, and its absence was not a gap in
 * permissions but a wall: TeamController has called authorize() since Phase 1,
 * Gate had no policy to call, and Gate's answer to that is deny. Every team
 * endpoint answered 403 to everyone, org admins included.
 *
 * Note what this policy does NOT do: it does not let a team's lead manage their
 * own team. That rule is tempting and wrong here. `if (lead)` is the hardcoded
 * role check docs/06 §2 rules out by name — the mechanism for "this person may
 * manage THIS team" is a scoped grant (`scoped_role_assignments`), which
 * PermissionResolver already resolves and which the roadmap puts in Phase 7.
 * Hardcoding it now would be the thing that has to be torn out then.
 */
final class TeamPolicy
{
    private ?MembershipModel $actor = null;

    private ?string $actorFor = null;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
    ) {}

    public function view(UserModel $user, TeamModel $team): bool
    {
        return $this->can('team.view');
    }

    public function update(UserModel $user, TeamModel $team): bool
    {
        return $this->can('team.update');
    }

    public function manageMembers(UserModel $user, TeamModel $team): bool
    {
        return $this->can('team.manage_members');
    }

    /**
     * Deleting is not the same as archiving.
     *
     * A team that existed carries history — assignments, work, membership
     * records — so removing it is an organization-level act, never something
     * its own lead can do on a bad afternoon.
     */
    public function delete(UserModel $user, TeamModel $team): bool
    {
        return $this->can('team.delete');
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
            $this->actor = MembershipModel::query()->find($membershipId);
            $this->actorFor = $membershipId;
        }

        return $this->actor;
    }
}

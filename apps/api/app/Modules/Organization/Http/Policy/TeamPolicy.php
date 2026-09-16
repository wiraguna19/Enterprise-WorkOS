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
 * **This policy twice refused to let a team's lead manage their own team**, and
 * the refusal is now paid rather than reversed. `if (lead)` would have been the
 * hardcoded role check docs/06 §2 rules out by name; the mechanism is a SCOPED
 * GRANT, and as of Phase 7 something can write one. So the question below is
 * "does this person hold team.update, or hold it ON THIS TEAM" — which is the
 * same sentence, asked of data instead of of an `if`.
 *
 * The difference is not cosmetic. A hardcoded lead check is one rule for one
 * relationship that the customer cannot see, change, or audit; a grant is a row
 * with a grantor, a timestamp and an activity record (ADR 0016).
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
        return $this->canOn('team.update', $team);
    }

    public function manageMembers(UserModel $user, TeamModel $team): bool
    {
        return $this->canOn('team.manage_members', $team);
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

    /**
     * Held across the organization, or held ON THIS TEAM.
     *
     * `hasOnScope()` answers the org-wide question first, so a grant is only
     * consulted for somebody who would otherwise be refused — which keeps the
     * common path one cached array lookup.
     */
    private function canOn(string $permission, TeamModel $team): bool
    {
        $actor = $this->actor();

        return $actor !== null && $this->permissions->hasOnScope(
            $actor,
            $permission,
            'team',
            (string) $team->getKey(),
        );
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

<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Policy;

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Organization\Infrastructure\Eloquent\DepartmentModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;

/**
 * Per-record authorization for departments: layer 4 of docs/06 §2.
 *
 * The tenant scope has already made other organizations' departments
 * unreachable, so nothing here re-checks the organization.
 *
 * **This class is the third instance of the same defect, and the second one
 * whose absence was a wall rather than a gap.** `MembershipPolicy` was written
 * and never registered (Phase 1 → 5). `TeamPolicy` did not exist at all
 * (Phase 1 → 5). This one did not exist either, while `DepartmentController`
 * has called `authorize('update', $department)` since Phase 2 — and Gate's
 * answer when it has no policy is deny. **`PATCH /departments/{id}` and
 * `POST /departments/{id}/move` answered 403 to everyone, org admins included,
 * for six phases.**
 *
 * Nothing caught it because nothing called them: they sat on the
 * `INTERFACE_OWED` list as "no department admin screen exists", and the hour a
 * screen reached them the 403 arrived. That is the argument for the list — an
 * unreachable endpoint is not merely unused, it is UNPOLICED — and the reason
 * the entry now records what its absence was hiding.
 *
 * Note what this policy does NOT do: it does not let a department's head
 * rename their own department. `if (head)` is the hardcoded role check
 * docs/06 §2 rules out by name; the mechanism for "this person may administer
 * THIS department" is a scoped grant, which PermissionResolver already resolves
 * and which the roadmap puts in Phase 7. Hardcoding it now would be the thing
 * that has to be torn out then — the same reasoning TeamPolicy records for
 * team leads.
 */
final class DepartmentPolicy
{
    private ?MembershipModel $actor = null;

    private ?string $actorFor = null;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
    ) {}

    public function view(UserModel $user, DepartmentModel $department): bool
    {
        return $this->can('department.view');
    }

    /**
     * Renaming and moving are one permission, deliberately.
     *
     * `department.update` is described in the catalogue as "Rename or move
     * departments" — one grant covering both, because an administrator trusted
     * to correct a name is trusted to correct where it sits. What a move must
     * not do is escape the domain's own refusals: cycles and depth are checked
     * inside the transaction that performs it, with row locks, and no
     * permission gets past them.
     */
    public function update(UserModel $user, DepartmentModel $department): bool
    {
        return $this->can('department.update');
    }

    /**
     * Archiving, which is what "delete" means here.
     *
     * A department that existed is referenced by projects, by people's
     * reporting lines and by every report grouped on it, so removing it is an
     * organization-level act.
     */
    public function delete(UserModel $user, DepartmentModel $department): bool
    {
        return $this->can('department.delete');
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

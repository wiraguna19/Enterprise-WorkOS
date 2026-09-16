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
 * **This policy refused to let a department's head rename their own
 * department**, and that refusal is now paid rather than reversed: `update`
 * asks whether the actor holds `department.update` across the organization OR
 * on THIS department, and Phase 7 can finally write the grant that answers
 * yes. `if (head)` would have been the hardcoded role check docs/06 §2 rules
 * out by name — one invisible rule for one relationship, where a grant is a row
 * somebody can see, revoke and audit (ADR 0016).
 *
 * `delete` is deliberately NOT scoped. See its own note.
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
        $actor = $this->actor();

        return $actor !== null && $this->permissions->hasOnScope(
            $actor,
            'department.update',
            'department',
            (string) $department->getKey(),
        );
    }

    /**
     * Archiving, which is what "delete" means here.
     *
     * A department that existed is referenced by projects, by people's
     * reporting lines and by every report grouped on it, so removing it is an
     * organization-level act.
     *
     * Unscoped on purpose, where `update` is scoped: a grant ON a department is
     * authority over what happens inside it, and erasing the department is not
     * something that happens inside it. Scoping this one would let a head who
     * was given a rename remove the thing every report groups on.
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

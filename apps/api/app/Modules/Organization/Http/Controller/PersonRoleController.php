<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controller;

use App\Modules\Identity\Application\Service\RoleAssignment;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\RoleModel;
use App\Modules\Organization\Http\Request\GrantRoleRequest;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;

/**
 * Who may do what, and where (docs/06 §2, docs/10 Phase 7).
 *
 * `scoped_role_assignments` has been in the schema since Phase 1 with nothing
 * able to write a row, and `person.invite` has been granted to managers and org
 * admins for seven phases with no endpoint behind it. This pays off the second
 * half of docs/11 §4 flow 2 — "assign role"; the invite half still has nothing
 * behind it, and flow 2 still asserts that absence.
 *
 * It lives beside the people endpoints because that is where somebody looks for
 * a person's roles, and calls Identity's service because Identity owns the
 * tables. The direction is the one docs/04 §3 allows.
 */
final class PersonRoleController extends ApiController
{
    public function __construct(
        private readonly RoleAssignment $roles,
    ) {}

    /**
     * The roles this organization has.
     *
     * An endpoint rather than a list in the interface, for the reason every
     * other vocabulary in this codebase is served: roles are ROWS. A customer's
     * own role is as real as a system one, and a form offering the four seeded
     * keys would be a form that cannot grant the fifth.
     */
    public function catalogue(): ApiResponse
    {
        $roles = RoleModel::query()->orderBy('level', 'desc')->get();

        return $this->ok($roles->map(fn (RoleModel $role): array => [
            'key' => $role->key,
            'name' => $role->name,
            'description' => $role->description,
            'level' => $role->level,
            'is_system' => $role->is_system,
        ]));
    }

    public function index(MembershipModel $membership): ApiResponse
    {
        $this->authorize('viewRoles', $membership);

        return $this->ok($this->roles->forMembership($membership));
    }

    public function store(GrantRoleRequest $request, MembershipModel $membership): ApiResponse
    {
        $this->authorize('manageRoles', $membership);

        $this->roles->grantOnScope(
            $membership,
            $request->string('role')->toString(),
            $request->string('scope_type')->toString(),
            $request->string('scope_id')->toString(),
        );

        // The whole set, not the row that was added. A grant changes what a
        // person can do, and the honest answer to "what did that do" is the
        // list it produced — which is also what the screen re-renders from.
        return $this->created($this->roles->forMembership($membership));
    }

    public function destroy(MembershipModel $membership, string $assignment): ApiResponse
    {
        $this->authorize('manageRoles', $membership);

        $this->roles->revokeScoped($membership, $assignment);

        return $this->noContent();
    }
}

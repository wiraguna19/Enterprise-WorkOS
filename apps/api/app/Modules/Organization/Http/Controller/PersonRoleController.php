<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controller;

use App\Modules\Identity\Application\Service\RoleAssignment;
use App\Modules\Identity\Application\Service\RoleBuilder;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\RoleModel;
use App\Modules\Organization\Http\Request\GrantRoleRequest;
use App\Modules\Organization\Http\Request\SaveRoleRequest;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;

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
        private readonly RoleBuilder $builder,
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

    /**
     * Every permission this build has.
     *
     * The catalogue is global — permissions are the product's vocabulary, not a
     * customer's — and it is served rather than listed in the interface for the
     * reason every vocabulary here is: a builder offering a permission the
     * product does not implement writes a role that grants nothing.
     */
    public function permissions(): ApiResponse
    {
        return $this->ok($this->builder->catalogue());
    }

    /** One role: what is in it, and how many people hold it. */
    public function showRole(string $key): ApiResponse
    {
        return $this->ok($this->builder->show($this->role($key)));
    }

    public function storeRole(SaveRoleRequest $request): ApiResponse
    {
        $role = $this->builder->create(
            $request->string('key')->toString(),
            $request->string('name')->toString(),
            $request->string('description')->toString(),
            $request->permissionKeys() ?? [],
            $request,
        );

        return $this->created($this->builder->show($role));
    }

    public function updateRole(SaveRoleRequest $request, string $key): ApiResponse
    {
        $role = $this->builder->update(
            $this->role($key),
            $request->has('name') ? $request->string('name')->toString() : null,
            $request->has('description') ? $request->string('description')->toString() : null,
            $request->permissionKeys(),
            $request,
        );

        return $this->ok($this->builder->show($role));
    }

    public function destroyRole(Request $request, string $key): ApiResponse
    {
        $this->builder->delete($this->role($key), $request);

        return $this->noContent();
    }

    /**
     * Roles are addressed by KEY, not by id.
     *
     * It is unique per organization, it is what a grant names, and it is what
     * somebody reads in an audit log. A uuid in the URL would make the audit
     * trail and the address bar disagree about what a role is called.
     */
    private function role(string $key): RoleModel
    {
        /** @var RoleModel $role */
        $role = RoleModel::query()->where('key', $key)->firstOrFail();

        return $role;
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

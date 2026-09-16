<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\RoleChangeRefused;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\RoleModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Roles a customer writes for themselves (docs/06 §2, docs/10 Phase 7).
 *
 * The product ships four roles and ADR 0016 recorded the gap they leave: "head
 * of Engineering" had to be spelled as `org_admin` scoped to a department,
 * because org_admin was the only role with `department.update` in it. That is a
 * blunt instrument — it carries every other permission into that department
 * too — and the answer is a role with exactly the permissions somebody means.
 *
 * ## You cannot create authority you do not have
 *
 * The refusal that matters here. Without it, `role.manage` is not "administer
 * roles", it is "invent any authority in the catalogue and hand it to
 * somebody" — and since granting to YOURSELF is refused (ADR 0016), the
 * escalation is one colleague long. So a role may only contain permissions the
 * author holds, and the check runs against the author's effective set at the
 * moment they save.
 *
 * It costs something real: an administrator who lacks `export.run` cannot build
 * an "Analyst" role that includes it, and has to be granted it first. That is
 * the correct shape — authority is delegated downward, never conjured sideways.
 *
 * ## The four seeded roles are not editable
 *
 * `is_system` roles are refused. Every permission test, the seed, docs/06 and
 * the demo data all assume org_admin means what it says, and a customer who
 * removes `organization.view` from it locks the whole organization out of its
 * own settings with no way back in through the product.
 */
final class RoleBuilder
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Every permission this build has, grouped the way the catalogue names them.
     *
     * The catalogue is global — permissions are the product's vocabulary, not a
     * customer's — so this is the same list for everybody, and it is served
     * rather than written into the interface for the reason every vocabulary
     * here is.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        $rows = DB::table('permissions')->orderBy('resource')->orderBy('action')->get();

        return array_values($rows->map(fn (object $row): array => [
            'key' => $row->key,
            'resource' => $row->resource,
            'action' => $row->action,
            'description' => $row->description,
        ])->all());
    }

    /**
     * One role, with what is in it and how many people hold it.
     *
     * The holder count is the number a person needs before pressing Delete, and
     * the reason this endpoint exists rather than the list carrying it: a count
     * per role on a list of twenty roles is twenty queries nobody asked for.
     *
     * @return array<string, mixed>
     */
    public function show(RoleModel $role): array
    {
        return [
            'key' => $role->key,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'level' => $role->level,
            'permissions' => $this->permissionKeys((string) $role->getKey()),
            'held_by' => $this->holders((string) $role->getKey())->count(),
        ];
    }

    /** @param list<string> $permissionKeys */
    public function create(
        string $key,
        string $name,
        string $description,
        array $permissionKeys,
        Request $request,
    ): RoleModel {
        return DB::transaction(function () use ($key, $name, $description, $permissionKeys, $request): RoleModel {
            $this->refuseBeyondYourOwnAuthority($permissionKeys);

            if (RoleModel::query()->where('key', $key)->exists()) {
                throw new RoleChangeRefused(
                    "This organization already has a role keyed `{$key}`.",
                    ['refusal' => 'duplicate_key', 'role' => $key],
                );
            }

            $role = new RoleModel;
            $role->forceFill([
                'id' => RoleModel::newId(),
                'key' => $key,
                'name' => $name,
                'description' => $description,
                // Never a system role. `is_system` is what protects the four the
                // product depends on, and a client that could set it would be
                // able to make its own role unremovable.
                'is_system' => false,
                // Below every seeded role. `level` orders roles for display and
                // for "who outranks whom" conversations; a custom role claiming
                // to outrank org_admin would be a lie told by a dropdown.
                'level' => 0,
            ])->save();

            $this->setPermissions((string) $role->getKey(), $permissionKeys);

            $this->audit->record('role.created', [
                'role' => $key,
                'permissions' => $permissionKeys,
            ], $request);

            return $role;
        });
    }

    /** @param list<string> $permissionKeys */
    public function update(
        RoleModel $role,
        ?string $name,
        ?string $description,
        ?array $permissionKeys,
        Request $request,
    ): RoleModel {
        return DB::transaction(function () use ($role, $name, $description, $permissionKeys, $request): RoleModel {
            $this->refuseSystemRole($role, 'changed');

            if ($permissionKeys !== null) {
                $this->refuseBeyondYourOwnAuthority($permissionKeys);
            }

            $before = $this->permissionKeys((string) $role->getKey());

            $role->forceFill(array_filter([
                'name' => $name,
                'description' => $description,
            ], static fn (mixed $value): bool => $value !== null))->save();

            if ($permissionKeys !== null) {
                $this->setPermissions((string) $role->getKey(), $permissionKeys);

                // Everybody holding this role has a cached permission set that
                // is now wrong. The cache is versioned per MEMBERSHIP, so there
                // is no single key to drop — every holder has to be bumped, and
                // a role nobody bumped is a permission change that applies in
                // fifteen minutes, to some people, depending on when they last
                // made a request.
                foreach ($this->holders((string) $role->getKey()) as $membershipId) {
                    $this->permissions->invalidate($membershipId);
                }
            }

            $this->audit->record('role.updated', [
                'role' => $role->key,
                'permissions' => ['from' => $before, 'to' => $permissionKeys ?? $before],
            ], $request);

            return $role;
        });
    }

    public function delete(RoleModel $role, Request $request): void
    {
        DB::transaction(function () use ($role, $request): void {
            $this->refuseSystemRole($role, 'removed');

            $held = $this->holders((string) $role->getKey())->count();

            if ($held > 0) {
                // Deleting would cascade the grants away and silently reduce
                // what those people can do — the kind of change that is noticed
                // a week later as "I used to be able to do this".
                throw new RoleChangeRefused(
                    "{$held} people hold this role. Take it off them first.",
                    ['refusal' => 'role_in_use', 'held_by' => $held],
                );
            }

            $role->delete();

            $this->audit->record('role.deleted', ['role' => $role->key], $request);
        });
    }

    /**
     * Refuse any permission the author does not hold.
     *
     * Checked against their EFFECTIVE set — the one `PermissionResolver`
     * computes — rather than against their roles, so a person who holds
     * something through two roles still holds it, and somebody whose role was
     * narrowed a minute ago cannot spend authority they no longer have.
     *
     * @param  list<string>  $permissionKeys
     */
    private function refuseBeyondYourOwnAuthority(array $permissionKeys): void
    {
        $actor = MembershipModel::query()->find($this->tenant->membershipId());

        if ($actor === null) {
            throw new RoleChangeRefused(
                'There is no acting membership to check this against.',
                ['refusal' => 'no_actor'],
            );
        }

        $mine = $this->permissions->permissionsFor($actor);
        $beyond = array_values(array_diff($permissionKeys, $mine));

        if ($beyond !== []) {
            throw new RoleChangeRefused(
                'A role cannot contain permissions you do not hold yourself: '.implode(', ', $beyond),
                ['refusal' => 'beyond_your_own_authority', 'permissions' => $beyond],
            );
        }
    }

    private function refuseSystemRole(RoleModel $role, string $verb): void
    {
        if ($role->is_system) {
            throw new RoleChangeRefused(
                "`{$role->key}` is one of the roles this product ships with and cannot be {$verb}.",
                ['refusal' => 'system_role', 'role' => $role->key],
            );
        }
    }

    /** @param list<string> $permissionKeys */
    private function setPermissions(string $roleId, array $permissionKeys): void
    {
        $ids = DB::table('permissions')->whereIn('key', $permissionKeys)->pluck('id', 'key');

        $unknown = array_values(array_diff($permissionKeys, $ids->keys()->all()));

        if ($unknown !== []) {
            // Named, because the alternative is a role that silently contains
            // less than the form showed.
            throw new RoleChangeRefused(
                'This build has no such permission: '.implode(', ', $unknown),
                ['refusal' => 'unknown_permission', 'permissions' => $unknown],
            );
        }

        DB::table('role_permissions')->where('role_id', $roleId)->delete();

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_permissions')->insert(
            $ids->map(fn (mixed $permissionId): array => [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ])->values()->all(),
        );
    }

    /** @return list<string> */
    private function permissionKeys(string $roleId): array
    {
        /** @var list<string> $keys */
        $keys = DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->orderBy('p.key')
            ->pluck('p.key')
            ->all();

        return $keys;
    }

    /**
     * Everybody holding this role, org-wide or on one thing.
     *
     * Both tables, because both are ways to hold it and a delete that counted
     * only one would leave the other's rows pointing at nothing.
     *
     * @return Collection<int, string>
     */
    private function holders(string $roleId): Collection
    {
        $organizationWide = DB::table('membership_roles')
            ->where('role_id', $roleId)
            ->pluck('membership_id');

        $scoped = DB::table('scoped_role_assignments')
            ->where('role_id', $roleId)
            ->pluck('membership_id');

        return $organizationWide->concat($scoped)->unique()->values()->map(strval(...));
    }
}

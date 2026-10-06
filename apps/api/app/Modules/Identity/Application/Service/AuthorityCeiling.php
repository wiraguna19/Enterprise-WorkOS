<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Nobody hands out more than they hold.
 *
 * RoleBuilder applied this to a role's contents and Invitations to the role an
 * invitation carries, each with its own copy. A scoped grant and a service
 * account's role had no ceiling at all: someone with `role.manage` and little
 * else could give a colleague — or a service account whose token they hold —
 * a role carrying permissions they had never been given. One answer, used by
 * every place that attaches a role to somebody.
 */
final class AuthorityCeiling
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * The permissions in this role that the acting person does not hold.
     *
     * Everything, when there is no acting membership: a check with nobody to
     * check against must not read as "nothing exceeds".
     *
     * @return list<string>
     */
    public function beyond(string $roleId): array
    {
        /** @var list<string> $granted */
        $granted = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->pluck('permissions.key')
            ->map(strval(...))
            ->all();

        $actor = MembershipModel::query()->find($this->tenant->membershipId());

        if ($actor === null) {
            return $granted;
        }

        return array_values(array_diff($granted, $this->permissions->permissionsFor($actor)));
    }
}

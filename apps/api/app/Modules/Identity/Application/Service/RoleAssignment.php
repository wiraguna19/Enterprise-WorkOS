<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Identity\Domain\Exception\RoleGrantRefused;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\RoleModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Granting and revoking roles — organization-wide, or ON one thing.
 *
 * `scoped_role_assignments` and `PermissionResolver::hasOnScope()` have existed
 * since Phase 1, described in the migration as "unused at MVP by design — it
 * exists now so that Phase 7 is a feature, not a migration of live permission
 * data". Nothing has ever written a row. This is the class that does, and it is
 * what lets `TeamPolicy` stop refusing a lead their own team without the
 * hardcoded `if (lead)` that docs/06 §2 rules out by name.
 *
 * Two things are load-bearing and easy to leave out:
 *
 * - **The permission cache is versioned, not deleted.** Every write here bumps
 *   the membership's version, or the person keeps their old permissions for up
 *   to fifteen minutes and the grant looks like it did nothing.
 * - **A role grant is an audit event.** Who may do what, and since when, is the
 *   first question asked after an incident, and `membership_roles` records
 *   `granted_at` and nothing about who granted it.
 */
final class RoleAssignment
{
    /** The scopes a grant may name — the CHECK constraint, in PHP. */
    public const SCOPES = ['project', 'team', 'department'];

    /** Which table holds each scope's subject, so a grant cannot name a row
     *  that does not exist. */
    private const SCOPE_TABLES = [
        'project' => 'projects',
        'team' => 'teams',
        'department' => 'departments',
    ];

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ActivityLogger $activity,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * What this person holds, and where.
     *
     * Organization-wide grants and scoped ones in one list, because "what can
     * this person do" is one question. A screen that showed only the first
     * would be describing a smaller person than the one in the database.
     *
     * @return array<string, mixed>
     */
    public function forMembership(MembershipModel $membership): array
    {
        $organizationWide = DB::table('membership_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.membership_id', $membership->getKey())
            ->orderBy('r.level', 'desc')
            ->get(['r.id as role_id', 'r.key', 'r.name', 'r.level', 'mr.granted_at']);

        $scoped = DB::table('scoped_role_assignments as sra')
            ->join('roles as r', 'r.id', '=', 'sra.role_id')
            ->where('sra.membership_id', $membership->getKey())
            ->orderBy('sra.created_at')
            ->get(['sra.id', 'r.id as role_id', 'r.key', 'r.name', 'sra.scope_type', 'sra.scope_id', 'sra.created_at']);

        return [
            'organization_wide' => $organizationWide->map(fn (object $row): array => [
                'role_id' => $row->role_id,
                'key' => $row->key,
                'name' => $row->name,
                'level' => (int) $row->level,
                'granted_at' => $row->granted_at,
            ])->all(),

            'denials' => $this->denialsFor($membership),

            'scoped' => $scoped->map(fn (object $row): array => [
                'id' => $row->id,
                'role_id' => $row->role_id,
                'key' => $row->key,
                'name' => $row->name,
                'scope_type' => $row->scope_type,
                'scope_id' => $row->scope_id,
                'scope_name' => $this->scopeName($row->scope_type, $row->scope_id),
                'created_at' => $row->created_at,
            ])->all(),
        ];
    }

    /**
     * Grant a role on one project, team or department.
     *
     * Scoped only. An organization-wide grant is a different act with a
     * different blast radius — it is how somebody becomes an administrator of
     * everything — and giving both the same control would let a slip of a
     * dropdown do the larger one.
     */
    public function grantOnScope(
        MembershipModel $membership,
        string $roleKey,
        string $scopeType,
        string $scopeId,
    ): string {
        return DB::transaction(function () use ($membership, $roleKey, $scopeType, $scopeId): string {
            $this->refuseErased($membership);

            $role = $this->role($roleKey);

            if (! in_array($scopeType, self::SCOPES, strict: true)) {
                throw new RoleGrantRefused(
                    'A role can be scoped to a project, a team or a department.',
                    ['refusal' => 'unknown_scope_type', 'scope_type' => $scopeType],
                );
            }

            $exists = DB::table(self::SCOPE_TABLES[$scopeType])
                ->where('organization_id', $this->tenant->organizationId())
                ->where('id', $scopeId)
                ->exists();

            if (! $exists) {
                // Checked rather than left to the absent foreign key: there is
                // no FK on (scope_type, scope_id) — it is polymorphic — so a
                // typo'd id would store a grant that resolves to nothing and
                // reads on screen as a perfectly good one.
                throw new RoleGrantRefused(
                    'That '.$scopeType.' does not exist.',
                    ['refusal' => 'scope_not_found', 'scope_type' => $scopeType, 'scope_id' => $scopeId],
                );
            }

            if ($this->holdsOrganizationWide($membership, (string) $role->id)) {
                // Not an error in the database — the unique index does not
                // cover this — but a grant that changes nothing, and one whose
                // row would survive the org-wide role being revoked and
                // quietly become load-bearing later.
                throw new RoleGrantRefused(
                    'They already hold that role across the whole organization.',
                    ['refusal' => 'already_organization_wide', 'role' => $roleKey],
                );
            }

            $id = (string) new UuidV7;

            DB::table('scoped_role_assignments')->insertOrIgnore([
                'id' => $id,
                'organization_id' => $this->tenant->organizationId(),
                'membership_id' => $membership->getKey(),
                'role_id' => $role->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'granted_by' => $this->tenant->membershipId(),
            ]);

            $this->settle($membership, 'role_granted', [
                'role' => ['from' => null, 'to' => $roleKey],
                'scope' => ['from' => null, 'to' => $scopeType.':'.$scopeId],
            ]);

            return $id;
        });
    }

    public function revokeScoped(MembershipModel $membership, string $assignmentId): void
    {
        DB::transaction(function () use ($membership, $assignmentId): void {
            $row = DB::table('scoped_role_assignments as sra')
                ->join('roles as r', 'r.id', '=', 'sra.role_id')
                ->where('sra.id', $assignmentId)
                // Scoped to the membership in the path as well as to the id: a
                // grant belonging to somebody else must not be revoked under an
                // authorization decision made about this person.
                ->where('sra.membership_id', $membership->getKey())
                ->first(['sra.id', 'r.key', 'sra.scope_type', 'sra.scope_id']);

            if ($row === null) {
                throw new RoleGrantRefused(
                    'That grant is not one of this person\'s.',
                    ['refusal' => 'grant_not_found'],
                );
            }

            DB::table('scoped_role_assignments')->where('id', $assignmentId)->delete();

            $this->settle($membership, 'role_revoked', [
                'role' => ['from' => $row->key, 'to' => null],
                'scope' => ['from' => $row->scope_type.':'.$row->scope_id, 'to' => null],
            ]);
        });
    }

    /**
     * What has been taken away from this person (ADR 0020).
     *
     * Beside the grants, not on a screen of its own: "what may they do" is one
     * question, and an interface that showed the grants and hid the denials
     * would answer it wrongly in the most confident way available.
     *
     * @return list<array<string, mixed>>
     */
    public function denialsFor(MembershipModel $membership): array
    {
        $rows = DB::table('permission_denials')
            ->where('membership_id', $membership->getKey())
            ->orderBy('permission_key')
            ->get(['id', 'permission_key', 'scope_type', 'scope_id', 'reason', 'created_at']);

        return array_values($rows->map(fn (object $row): array => [
            'id' => $row->id,
            'permission' => $row->permission_key,
            'scope_type' => $row->scope_type,
            'scope_id' => $row->scope_id,
            'scope_name' => $row->scope_type === null
                ? null
                : $this->scopeName((string) $row->scope_type, (string) $row->scope_id),
            'reason' => $row->reason,
            'created_at' => $row->created_at,
        ])->all());
    }

    /**
     * Take one permission away from one person.
     *
     * Deny wins over every grant, which is what makes it worth having and also
     * what makes it dangerous — so the obvious guard to write here is "refuse a
     * denial of `role.manage` that would leave nobody able to lift it". It is
     * not written, on purpose: `MembershipPolicy::manageRoles()` refuses a
     * denial aimed at yourself, and the actor holds `role.manage` org-wide, so
     * at the moment any denial is written there is always at least one other
     * person who can lift it. That guard could not fire, and this codebase
     * already keeps a test whose whole job is to find code nothing reaches.
     */
    public function deny(
        MembershipModel $membership,
        string $permissionKey,
        ?string $scopeType,
        ?string $scopeId,
        string $reason,
    ): string {
        return DB::transaction(function () use ($membership, $permissionKey, $scopeType, $scopeId, $reason): string {
            $this->refuseErased($membership);

            if (! DB::table('permissions')->where('key', $permissionKey)->exists()) {
                throw new RoleGrantRefused(
                    "This build has no permission keyed `{$permissionKey}`.",
                    ['refusal' => 'unknown_permission', 'permission' => $permissionKey],
                );
            }

            if ($scopeType !== null) {
                $this->assertScopeExists($scopeType, (string) $scopeId);
            }

            $id = (string) new UuidV7;

            DB::table('permission_denials')->insertOrIgnore([
                'id' => $id,
                'organization_id' => $this->tenant->organizationId(),
                'membership_id' => $membership->getKey(),
                'permission_key' => $permissionKey,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'reason' => $reason,
                'denied_by' => $this->tenant->membershipId(),
            ]);

            $this->settle($membership, 'permission_denied', [
                'permission' => ['from' => null, 'to' => $permissionKey],
                'scope' => ['from' => null, 'to' => $scopeType === null ? 'everywhere' : $scopeType.':'.$scopeId],
                'reason' => ['from' => null, 'to' => $reason],
            ]);

            return $id;
        });
    }

    public function liftDenial(MembershipModel $membership, string $denialId): void
    {
        DB::transaction(function () use ($membership, $denialId): void {
            $row = DB::table('permission_denials')
                ->where('id', $denialId)
                // Scoped to the membership in the path: one person's denial
                // must not be lifted under a decision made about another.
                ->where('membership_id', $membership->getKey())
                ->first(['permission_key']);

            if ($row === null) {
                throw new RoleGrantRefused(
                    'That denial is not one of this person\'s.',
                    ['refusal' => 'denial_not_found'],
                );
            }

            DB::table('permission_denials')->where('id', $denialId)->delete();

            $this->settle($membership, 'permission_denial_lifted', [
                'permission' => ['from' => $row->permission_key, 'to' => null],
            ]);
        });
    }

    /**
     * Why this person can — or cannot — do one thing.
     *
     * The reader a deny model owes. Without it, "you may not" is a wall with no
     * sign on it: the person hitting it cannot tell whether they were never
     * granted the permission, or held it and had it taken away, and neither can
     * the administrator they ask.
     *
     * @return array<string, mixed>
     */
    public function explain(MembershipModel $membership, string $permissionKey): array
    {
        $throughRoles = DB::table('membership_roles as mr')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.membership_id', $membership->getKey())
            ->where('p.key', $permissionKey)
            ->pluck('r.name');

        $scopedGrants = DB::table('scoped_role_assignments as sra')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'sra.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('roles as r', 'r.id', '=', 'sra.role_id')
            ->where('sra.membership_id', $membership->getKey())
            ->where('p.key', $permissionKey)
            ->get(['r.name as role', 'sra.scope_type', 'sra.scope_id']);

        $denials = DB::table('permission_denials')
            ->where('membership_id', $membership->getKey())
            ->where('permission_key', $permissionKey)
            ->get(['scope_type', 'scope_id', 'reason']);

        return [
            'permission' => $permissionKey,
            // The organization-wide answer, computed the way every check does:
            // a denial with no scope beats the roles above it.
            'allowed' => $this->permissions->has($membership, $permissionKey),
            'granted_by' => array_values($throughRoles->map(strval(...))->all()),
            'granted_on' => array_values($scopedGrants->map(fn (object $row): array => [
                'role' => $row->role,
                'scope_type' => $row->scope_type,
                'scope_name' => $this->scopeName((string) $row->scope_type, (string) $row->scope_id),
            ])->all()),
            'denied_by' => array_values($denials->map(fn (object $row): array => [
                'scope_type' => $row->scope_type,
                'scope_name' => $row->scope_type === null
                    ? null
                    : $this->scopeName((string) $row->scope_type, (string) $row->scope_id),
                'reason' => $row->reason,
            ])->all()),
        ];
    }

    /**
     * Nothing is granted to, or taken from, somebody who has been erased.
     *
     * Found by opening the screen: a person the product had just announced as
     * erased was still offered a form to make them Organization Admin. The
     * interface was fixed in the same commit, but the refusal belongs HERE —
     * an erased membership holds no roles by definition (the erasure deleted
     * them), and a grant written against one would be authority handed to an
     * identity that no longer exists (ADR 0022).
     */
    private function refuseErased(MembershipModel $membership): void
    {
        if ($membership->erased_at !== null) {
            throw new RoleGrantRefused(
                'This person has been erased from this organization.',
                ['refusal' => 'person_erased'],
            );
        }
    }

    private function assertScopeExists(string $scopeType, string $scopeId): void
    {
        if (! in_array($scopeType, self::SCOPES, strict: true)) {
            throw new RoleGrantRefused(
                'A denial can be scoped to a project, a team or a department.',
                ['refusal' => 'unknown_scope_type', 'scope_type' => $scopeType],
            );
        }

        $exists = DB::table(self::SCOPE_TABLES[$scopeType])
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $scopeId)
            ->exists();

        if (! $exists) {
            throw new RoleGrantRefused(
                'That '.$scopeType.' does not exist.',
                ['refusal' => 'scope_not_found', 'scope_type' => $scopeType, 'scope_id' => $scopeId],
            );
        }
    }

    private function role(string $key): RoleModel
    {
        $role = RoleModel::query()->where('key', $key)->first();

        if ($role === null) {
            throw new RoleGrantRefused(
                "This organization has no role keyed `{$key}`.",
                ['refusal' => 'unknown_role', 'role' => $key],
            );
        }

        return $role;
    }

    private function holdsOrganizationWide(MembershipModel $membership, string $roleId): bool
    {
        return DB::table('membership_roles')
            ->where('membership_id', $membership->getKey())
            ->where('role_id', $roleId)
            ->exists();
    }

    /**
     * The two things every write here owes: a fresh permission set, and a
     * record of who changed it.
     *
     * @param  array<string, mixed>  $changes
     */
    private function settle(MembershipModel $membership, string $verb, array $changes): void
    {
        // Versioned, not deleted — `invalidate()` bumps a counter, which
        // orphans the scoped cache keys too. Without this the person keeps the
        // permissions they had for up to fifteen minutes, and the grant looks
        // like it did nothing at all.
        $this->permissions->invalidate((string) $membership->getKey());

        $this->activity->record('membership', (string) $membership->getKey(), $verb, $changes);
    }

    private function scopeName(string $scopeType, string $scopeId): ?string
    {
        $table = self::SCOPE_TABLES[$scopeType] ?? null;

        if ($table === null) {
            return null;
        }

        $name = DB::table($table)->where('id', $scopeId)->value('name');

        return is_string($name) ? $name : null;
    }
}

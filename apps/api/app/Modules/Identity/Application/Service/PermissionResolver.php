<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the effective permission set for a membership.
 *
 * A permission check happens many times per request, on every list row that
 * renders a `permissions` block. It must never be a database round trip in the
 * hot path — hence the cache. Correctness under change comes from a version
 * counter rather than from TTL expiry: any role grant, revocation, or scoped
 * assignment bumps the membership's version, which changes the cache key, so
 * the old entry is unreachable immediately (docs/06 §2).
 *
 * TTL exists only as a backstop against a missed invalidation.
 */
final class PermissionResolver
{
    private const TTL_SECONDS = 900;

    /** Per-request memoization; the same membership is resolved many times. */
    /** @var array<string, list<string>> */
    private array $memo = [];

    /** @return list<string> */
    public function permissionsFor(MembershipModel $membership): array
    {
        $key = $this->cacheKey($membership);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        /** @var list<string> $permissions */
        $permissions = Cache::remember(
            $key,
            self::TTL_SECONDS,
            fn (): array => $this->resolveFromDatabase($membership),
        );

        return $this->memo[$key] = $permissions;
    }

    public function has(MembershipModel $membership, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($membership), strict: true);
    }

    /**
     * Scoped grants: "Manager OF project X".
     *
     * Unused at MVP — org-wide roles cover every current case — but resolved
     * here from the first release so that enabling it in Phase 7 is a UI change,
     * not a migration of live permission data (docs/12 §10).
     */
    public function hasOnScope(
        MembershipModel $membership,
        string $permission,
        string $scopeType,
        string $scopeId,
    ): bool {
        if ($this->has($membership, $permission)) {
            return true;
        }

        $key = $this->cacheKey($membership)."|scope:{$scopeType}:{$scopeId}";

        /** @var list<string> $scoped */
        $scoped = Cache::remember(
            $key,
            self::TTL_SECONDS,
            fn (): array => $this->resolveScopedFromDatabase($membership, $scopeType, $scopeId),
        );

        return in_array($permission, $scoped, strict: true);
    }

    /**
     * Invalidate by bumping the version, not by deleting keys.
     *
     * Deleting is unreliable across cache clusters and impossible to do
     * exhaustively for scoped keys; a version bump orphans all of them at once.
     */
    public function invalidate(string $membershipId): void
    {
        Cache::increment($this->versionKey($membershipId));
    }

    /** @return list<string> */
    private function resolveFromDatabase(MembershipModel $membership): array
    {
        /** @var list<string> $keys */
        $keys = DB::table('membership_roles as mr')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('mr.membership_id', $membership->getKey())
            ->where('mr.organization_id', $membership->organization_id)
            ->distinct()
            ->pluck('p.key')
            ->all();

        return $keys;
    }

    /** @return list<string> */
    private function resolveScopedFromDatabase(
        MembershipModel $membership,
        string $scopeType,
        string $scopeId,
    ): array {
        /** @var list<string> $keys */
        $keys = DB::table('scoped_role_assignments as sra')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'sra.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('sra.membership_id', $membership->getKey())
            ->where('sra.organization_id', $membership->organization_id)
            ->where('sra.scope_type', $scopeType)
            ->where('sra.scope_id', $scopeId)
            ->distinct()
            ->pluck('p.key')
            ->all();

        return $keys;
    }

    private function cacheKey(MembershipModel $membership): string
    {
        $version = Cache::get($this->versionKey((string) $membership->getKey()), 0);

        return "perms:{$membership->getKey()}:v{$version}";
    }

    private function versionKey(string $membershipId): string
    {
        return "perms_version:{$membershipId}";
    }
}

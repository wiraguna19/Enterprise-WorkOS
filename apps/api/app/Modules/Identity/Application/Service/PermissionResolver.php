<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use Illuminate\Database\Query\Builder;
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
 *
 * **Grants union; denials subtract, and denials win** (ADR 0020). Every
 * permission in this product was a grant until Phase 7, and the one shape that
 * could not be expressed was "they are an Employee, and this one person may not
 * export". A denial is a row against a MEMBERSHIP, optionally scoped, and it
 * defeats every grant — org-wide or scoped — because a deny a later grant could
 * silently overturn is a deny nobody can trust.
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
        // A denial ON THIS SCOPE has to be consulted before the org-wide
        // answer, or somebody denied `department.update` on Marketing alone
        // would still pass on the strength of holding it everywhere — which
        // is precisely the case a scoped denial exists for.
        if ($this->isDenied($membership, $permission, $scopeType, $scopeId)) {
            return false;
        }

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
            // Denials subtract IN THE SAME STATEMENT (ADR 0020). Reading them
            // separately and diffing in PHP is the same answer and one more
            // round trip on every uncached resolution — which six query budgets
            // caught within an hour of it being written, each of them sitting
            // exactly on its limit.
            ->whereNotExists(fn (Builder $denied): Builder => $denied
                ->selectRaw('1')
                ->from('permission_denials as pd')
                ->whereColumn('pd.permission_key', 'p.key')
                ->where('pd.membership_id', $membership->getKey())
                ->where('pd.organization_id', $membership->organization_id)
                ->whereNull('pd.scope_type'))
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
            // Denied here, or denied everywhere: "may not export, anywhere"
            // has to hold inside every project too, or the word means nothing.
            ->whereNotExists(fn (Builder $denied): Builder => $denied
                ->selectRaw('1')
                ->from('permission_denials as pd')
                ->whereColumn('pd.permission_key', 'p.key')
                ->where('pd.membership_id', $membership->getKey())
                ->where('pd.organization_id', $membership->organization_id)
                ->where(fn (Builder $where): Builder => $where
                    ->whereNull('pd.scope_type')
                    ->orWhere(fn (Builder $here): Builder => $here
                        ->where('pd.scope_type', $scopeType)
                        ->where('pd.scope_id', $scopeId))))
            ->distinct()
            ->pluck('p.key')
            ->all();

        return $keys;
    }

    /**
     * Everything denied to this membership at one scope, and everywhere.
     *
     * The only denial read that is still a statement of its own: the two
     * resolution queries above subtract in their own SQL, but `hasOnScope()`
     * needs the answer BEFORE the org-wide cache is consulted, and there is no
     * statement of its own to fold it into.
     *
     * @return list<string>
     */
    private function deniedKeys(
        MembershipModel $membership,
        string $scopeType,
        string $scopeId,
    ): array {
        /** @var list<string> $keys */
        $keys = DB::table('permission_denials')
            ->where('membership_id', $membership->getKey())
            ->where('organization_id', $membership->organization_id)
            // Grouped, because this sits under a WHERE on the membership: an
            // ungrouped orWhere would ask a question about everybody.
            ->where(function (Builder $query) use ($scopeType, $scopeId): void {
                $query->whereNull('scope_type')
                    ->orWhere(fn (Builder $here): Builder => $here
                        ->where('scope_type', $scopeType)
                        ->where('scope_id', $scopeId));
            })
            ->distinct()
            ->pluck('permission_key')
            ->all();

        return $keys;
    }

    /**
     * Asked directly, outside the cached set.
     *
     * `hasOnScope()` needs the answer BEFORE consulting the org-wide cache, and
     * a denial read from a cache keyed by scope would not be seen by the
     * org-wide lookup at all.
     */
    private function isDenied(
        MembershipModel $membership,
        string $permission,
        string $scopeType,
        string $scopeId,
    ): bool {
        return in_array(
            $permission,
            $this->deniedKeys($membership, $scopeType, $scopeId),
            strict: true,
        );
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

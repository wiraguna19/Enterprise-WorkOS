<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Tenancy;

use App\Modules\Platform\Domain\Exception\TenantContextMissing;
use Illuminate\Support\Facades\Log;

/**
 * The single source of truth for "which organization is this request acting on".
 *
 * Bound as a singleton per request. It is resolved exactly once, from the
 * authenticated session (never from a client-supplied header or body field),
 * and is immutable thereafter except through the explicit, audited escape
 * hatches below.
 *
 * See docs/01-system-architecture.md §6 and docs/06-auth-and-authorization.md §1.
 */
final class TenantContext
{
    private ?string $organizationId = null;

    private ?string $membershipId = null;

    private ?string $userId = null;

    /** True while running deliberately outside any tenant (platform maintenance). */
    private bool $platformMode = false;

    public function setFromSession(string $organizationId, string $membershipId, string $userId): void
    {
        if ($this->organizationId !== null && $this->organizationId !== $organizationId) {
            // Re-binding a different tenant inside one request is always a bug.
            throw new \LogicException('Tenant context has already been resolved for this request.');
        }

        $this->organizationId = $organizationId;
        $this->membershipId = $membershipId;
        $this->userId = $userId;
    }

    public function organizationId(): string
    {
        if ($this->organizationId === null) {
            throw new TenantContextMissing(
                'No tenant context. A tenant-scoped query ran outside an organization-bound request.'
            );
        }

        return $this->organizationId;
    }

    public function membershipId(): string
    {
        if ($this->membershipId === null) {
            throw new TenantContextMissing('No membership bound to the current tenant context.');
        }

        return $this->membershipId;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function hasTenant(): bool
    {
        return $this->organizationId !== null;
    }

    /**
     * Distinct from hasTenant().
     *
     * A queued job bound with runFor() has an organization but no membership —
     * it acts as the system, not as a person. Callers that attribute an action
     * to an actor must check this, or a background job will throw where a
     * request would have worked.
     */
    public function hasMembership(): bool
    {
        return $this->membershipId !== null;
    }

    public function isPlatformMode(): bool
    {
        return $this->platformMode;
    }

    /**
     * Run a callback with tenant scoping disabled.
     *
     * This is the ONLY legal way to query across tenants. It is deliberately
     * verbose, it is logged, and its call sites are asserted in the test suite —
     * there is no `withoutGlobalScopes()` shortcut anywhere in the codebase.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function runAsPlatform(string $reason, callable $callback): mixed
    {
        $previous = $this->platformMode;
        $this->platformMode = true;

        Log::info('tenancy.platform_mode_entered', [
            'reason' => $reason,
            'user_id' => $this->userId,
        ]);

        try {
            return $callback();
        } finally {
            $this->platformMode = $previous;
        }
    }

    /**
     * Bind a tenant for a queued job or console command, which has no request.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function runFor(string $organizationId, callable $callback): mixed
    {
        $previousOrg = $this->organizationId;
        $previousMembership = $this->membershipId;

        $this->organizationId = $organizationId;
        $this->membershipId = null;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previousOrg;
            $this->membershipId = $previousMembership;
        }
    }

    /**
     * Bind a tenant AND a person, for a request that carries neither.
     *
     * The calendar feed is the case: a subscription URL arrives with no session,
     * and the row it resolves to is what says who is asking. Distinct from
     * runFor(), which binds an organization and leaves the membership null
     * because a job acts as the system — here there IS a person, and the
     * visibility rules that follow need to know which one (docs/06 §2).
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function runForMembership(string $organizationId, string $membershipId, callable $callback): mixed
    {
        $previousOrg = $this->organizationId;
        $previousMembership = $this->membershipId;

        $this->organizationId = $organizationId;
        $this->membershipId = $membershipId;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previousOrg;
            $this->membershipId = $previousMembership;
        }
    }

    /** Test-support only: clear the context between test cases. */
    public function reset(): void
    {
        $this->organizationId = null;
        $this->membershipId = null;
        $this->userId = null;
        $this->platformMode = false;
    }
}

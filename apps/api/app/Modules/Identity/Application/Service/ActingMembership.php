<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;

/**
 * The acting membership row, fetched once per request.
 *
 * Almost everything downstream of authorization needs it: the visibility scope,
 * every policy, every permission check. Each of those used to look it up for
 * itself, so a list of 50 rows re-read the SAME row five times per request —
 * invisible in review, and one of the query budgets in docs/11 §3 exists
 * precisely to catch it.
 *
 * Registered as a SCOPED binding, never a singleton: the cached row must die
 * with the request, or a revoked membership would keep working until the worker
 * restarted.
 */
final class ActingMembership
{
    private ?MembershipModel $membership = null;

    private ?string $resolvedFor = null;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Hand over the row the tenant resolver has already read.
     *
     * ResolveTenant must load the membership anyway — that lookup IS the
     * re-check that makes offboarding take effect within one request. Priming
     * from it means the rest of the request reuses that row instead of asking
     * for it a second time.
     */
    public function prime(MembershipModel $membership): void
    {
        $this->resolvedFor = (string) $membership->getKey();
        $this->membership = $membership;
    }

    public function get(): ?MembershipModel
    {
        $membershipId = $this->tenant->membershipId();

        if ($this->resolvedFor !== $membershipId) {
            $this->resolvedFor = $membershipId;
            $this->membership = MembershipModel::query()->find($membershipId);
        }

        return $this->membership;
    }

    public function getOrFail(): MembershipModel
    {
        $membership = $this->get();

        if ($membership === null) {
            throw new \RuntimeException('No membership bound to the current tenant context.');
        }

        return $membership;
    }
}

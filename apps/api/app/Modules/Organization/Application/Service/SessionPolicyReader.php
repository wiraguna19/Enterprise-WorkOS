<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Service;

use App\Modules\Organization\Infrastructure\Eloquent\OrganizationModel;
use App\Modules\Platform\Domain\Contract\SessionPolicy;

/**
 * Organization's answer to "how long may a session here live" (ADR 0028).
 *
 * Asked during login, BEFORE the tenant resolver has run — the same moment
 * `resolveMembership` documents as pre-tenant, because signing in is the call
 * that chooses the tenant. It works there because `organizations` is the tenant
 * rather than a tenant-scoped table and carries no organization scope; a model
 * that did would answer the default for every login in the product, and would
 * look entirely correct in any test that signs in first.
 *
 * The soft-delete scope is left ON. A closed organization has no business
 * issuing sessions, and reading its window would be the quiet half of that.
 */
final class SessionPolicyReader implements SessionPolicy
{
    public function sessionLifetimeDays(string $organizationId): int
    {
        $days = OrganizationModel::query()
            ->whereKey($organizationId)
            ->value('session_lifetime_days');

        // Cast rather than trusted: this is a raw column read (`value()` skips
        // the model's casts), and a smallint comes back as an int from one
        // driver and a string from another. `'7' + 0` days is not a thing
        // anybody should have to debug at a login prompt.
        return is_numeric($days) ? (int) $days : self::DEFAULT_LIFETIME_DAYS;
    }
}

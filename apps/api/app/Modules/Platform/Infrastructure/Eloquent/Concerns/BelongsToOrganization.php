<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Eloquent\Concerns;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Infrastructure\Eloquent\Scope\OrganizationScope;

/**
 * Applied to EVERY tenant-scoped model. There is no opt-out flag.
 *
 * Two guarantees:
 *   1. Reads are filtered to the current organization by a global scope that
 *      THROWS if no tenant context exists (rather than silently returning
 *      everything, which is how leaks ship).
 *   2. Writes fill organization_id from the context and ignore any
 *      client-supplied value.
 *
 * The isolation test suite discovers every user of this trait by reflection and
 * asserts both guarantees — a model that forgets the trait fails CI.
 *
 * See docs/01-system-architecture.md §6.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model): void {
            $context = app(TenantContext::class);

            if ($context->isPlatformMode()) {
                // Platform-mode writes must set the column explicitly and on purpose.
                if ($model->getAttribute('organization_id') === null) {
                    throw new \LogicException(
                        static::class.' created in platform mode without an explicit organization_id.'
                    );
                }

                return;
            }

            // The context wins. A client-supplied organization_id is never trusted.
            $model->setAttribute('organization_id', $context->organizationId());
        });
    }
}

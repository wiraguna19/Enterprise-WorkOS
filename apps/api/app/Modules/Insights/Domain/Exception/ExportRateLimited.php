<?php

declare(strict_types=1);

namespace App\Modules\Insights\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Five exports an hour per organization (docs/05 §6, ADR 0011).
 *
 * Thrown from the controller rather than enforced by `throttle:` middleware,
 * and that is not a preference: the framework's ThrottleRequests sits high in
 * the middleware priority list and runs BEFORE this product's tenant resolver,
 * so a limiter keyed on the organization has no organization to key on and
 * throws `TenantContextMissing` instead of limiting anything. Keying it on the
 * IP instead would have looked fine and quietly enforced a different rule than
 * the one docs/05 specifies.
 */
final class ExportRateLimited extends DomainException
{
    public function errorCode(): string
    {
        return 'report.export_rate_limited';
    }

    public function httpStatus(): int
    {
        return 429;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exception;

/**
 * Thrown when a tenant-scoped query runs with no organization bound.
 *
 * This is always a programming error, never a user error: it means a query
 * escaped the request lifecycle, or a queued job forgot TenantContext::runFor().
 * It fails the request rather than returning unscoped data.
 */
final class TenantContextMissing extends \LogicException {}

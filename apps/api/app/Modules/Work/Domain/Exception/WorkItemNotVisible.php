<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Raised as 404, never 403: a 403 would confirm the item exists (docs/05 §3).
 */
final class WorkItemNotVisible extends DomainException
{
    public function errorCode(): string
    {
        return 'work_item.not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}

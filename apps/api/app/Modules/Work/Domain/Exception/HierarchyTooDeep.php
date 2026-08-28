<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Five levels is already past what anyone can hold in their head, and unbounded
 * depth makes every roll-up query recursive.
 */
final class HierarchyTooDeep extends DomainException
{
    public function errorCode(): string
    {
        return 'work_item.hierarchy_too_deep';
    }
}

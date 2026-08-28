<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * A department cannot become its own ancestor. Allowing it would detach the
 * subtree from the root and make it unreachable by any path query.
 */
final class DepartmentCycle extends DomainException
{
    public function errorCode(): string
    {
        return 'department.cycle_detected';
    }
}

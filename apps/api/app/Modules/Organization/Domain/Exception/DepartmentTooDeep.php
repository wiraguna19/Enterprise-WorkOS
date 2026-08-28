<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * The depth limit is not arbitrary: the materialized path column is bounded,
 * and an org chart deeper than six levels is a modelling mistake rather than a
 * requirement.
 */
final class DepartmentTooDeep extends DomainException
{
    public function errorCode(): string
    {
        return 'department.too_deep';
    }
}

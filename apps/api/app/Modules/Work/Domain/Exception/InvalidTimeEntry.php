<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * The hours claimed cannot be true.
 *
 * 422 rather than 409: nothing about the system's state conflicts, the number
 * itself is wrong — and the details name what is already on that day, so the
 * person can see why without opening their timesheet.
 */
final class InvalidTimeEntry extends DomainException
{
    public function errorCode(): string
    {
        return 'time_entry.invalid';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}

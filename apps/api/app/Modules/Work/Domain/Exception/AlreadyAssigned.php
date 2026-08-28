<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * That person already holds this role on this item.
 */
final class AlreadyAssigned extends DomainException
{
    public function errorCode(): string
    {
        return 'work_item.already_assigned';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

final class NoActiveMembership extends DomainException
{
    public function errorCode(): string
    {
        return 'auth.no_active_membership';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}

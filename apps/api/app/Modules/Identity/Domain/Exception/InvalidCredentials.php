<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

final class InvalidCredentials extends DomainException
{
    public function errorCode(): string
    {
        return 'auth.invalid_credentials';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}

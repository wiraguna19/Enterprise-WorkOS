<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

final class AlreadyOnTeam extends DomainException
{
    public function errorCode(): string
    {
        return 'team.member_already_active';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

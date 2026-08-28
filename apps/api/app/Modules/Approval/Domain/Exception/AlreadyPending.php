<?php

declare(strict_types=1);

namespace App\Modules\Approval\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Two open approvals on the same subject is not a state anyone can reason about: which decision wins?
 */
final class AlreadyPending extends DomainException
{
    public function errorCode(): string
    {
        return 'approval.already_pending';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

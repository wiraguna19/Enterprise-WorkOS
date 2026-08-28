<?php

declare(strict_types=1);

namespace App\Modules\Approval\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Deciding on a review you were not asked for. Separate from a permission failure: the actor may hold approval.decide and still not be on THIS roster.
 */
final class NotAnApprover extends DomainException
{
    public function errorCode(): string
    {
        return 'approval.not_an_approver';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}

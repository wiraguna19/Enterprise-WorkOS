<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Closing work with open blockers is refused. The override exists (docs/02 §4.2)
 * because real organisations need it — but it must be explicit and recorded,
 * never silent.
 */
final class CannotCloseBlockedWorkItem extends DomainException
{
    public function errorCode(): string
    {
        return 'work_item.blocked_by_dependencies';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

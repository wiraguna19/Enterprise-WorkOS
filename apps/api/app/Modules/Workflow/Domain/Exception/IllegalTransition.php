<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * The move is not in the workflow, or a guard refuses it.
 *
 * 409 rather than 403: the actor may well have permission — the WORK is in the
 * wrong state. Conflating "you may not" with "not from here" sends people to
 * an administrator who cannot help them.
 */
final class IllegalTransition extends DomainException
{
    public function errorCode(): string
    {
        return 'workflow.illegal_transition';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

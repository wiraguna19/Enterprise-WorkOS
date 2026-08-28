<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * A rule names an action this version does not have.
 *
 * A hard error rather than a skip: a rule that silently does nothing looks
 * exactly like a rule that is working.
 */
final class UnknownAction extends DomainException
{
    public function errorCode(): string
    {
        return 'workflow.unknown_action';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * A grant that would not mean what it appears to mean.
 *
 * 409 rather than 422: the request is well-formed and the actor holds
 * `role.manage` — it is the COMBINATION that is refused, and the reason is
 * always about the effect the grant would have rather than about its shape.
 *
 * `details.refusal` names which rule refused, so an interface can be specific
 * without parsing prose.
 */
final class RoleGrantRefused extends DomainException
{
    public function errorCode(): string
    {
        return 'role.grant_refused';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

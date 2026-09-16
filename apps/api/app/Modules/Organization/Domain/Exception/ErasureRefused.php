<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * An erasure that would not mean what it appears to mean (ADR 0022).
 *
 * 409 rather than 422: the request is well-formed and the actor is allowed to
 * erase people — it is this person, or this second attempt, that is refused,
 * and the reason is always about the effect rather than the shape.
 *
 * `details.refusal` names which rule refused, so an interface can be specific
 * without parsing prose.
 */
final class ErasureRefused extends DomainException
{
    public function errorCode(): string
    {
        return 'person.erasure_refused';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

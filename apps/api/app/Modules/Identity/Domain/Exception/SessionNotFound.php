<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * A session that is not yours, or is already over.
 *
 * 404 rather than 403, and the two cases answer identically: a session id is a
 * uuid, and telling somebody "that one exists but is not yours" would confirm a
 * guess about another account.
 */
final class SessionNotFound extends DomainException
{
    public function errorCode(): string
    {
        return 'session.not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}

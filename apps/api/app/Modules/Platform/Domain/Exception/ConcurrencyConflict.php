<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exception;

/**
 * Optimistic lock failure — the record changed since the client read it.
 *
 * Returns 409 with the current server state so the client can present a real
 * merge instead of silently overwriting someone else's edit.
 */
final class ConcurrencyConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'concurrency.conflict';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

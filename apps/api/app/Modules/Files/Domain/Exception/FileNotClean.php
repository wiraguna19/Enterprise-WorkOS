<?php

declare(strict_types=1);

namespace App\Modules\Files\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Covers "still scanning" as well as "infected".
 *
 * The two are deliberately the same refusal from the caller's point of view:
 * an unscanned file and a rejected one are both unavailable, and distinguishing
 * them in the response would tell an attacker when scanning finishes.
 */
final class FileNotClean extends DomainException
{
    public function errorCode(): string
    {
        return 'file.not_available';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}

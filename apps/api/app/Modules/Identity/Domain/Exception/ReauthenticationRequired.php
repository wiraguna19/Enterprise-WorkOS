<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * This act needs the password again, right now (ADR 0034).
 *
 * 403 rather than 401: the session is perfectly valid and nothing about it is
 * being questioned. A 401 would tell every client in the world to throw the
 * session away and send somebody back to the sign-in screen, which is both
 * wrong and the opposite of what this asks for — the point is to keep them
 * where they are and have them confirm one thing.
 *
 * `details.window_minutes` so an interface can say how long the confirmation
 * lasts without hard-coding a number the server owns.
 */
final class ReauthenticationRequired extends DomainException
{
    public function errorCode(): string
    {
        return 'auth.reauthentication_required';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}

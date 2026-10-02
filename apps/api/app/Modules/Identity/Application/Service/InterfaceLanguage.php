<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Identity\Infrastructure\Eloquent\UserModel;

/**
 * A person's interface language (ADR 0060).
 *
 * The languages the web speaks, and the one write that chooses between them.
 * A service rather than a line in the controller because controllers here
 * never write (tests/Arch): the day the API learns `Accept-Language`, this is
 * also where the list it may answer in lives.
 */
final class InterfaceLanguage
{
    /** @var list<string> */
    public const LOCALES = ['en', 'id'];

    public function set(UserModel $user, string $locale): void
    {
        $user->forceFill(['locale' => $locale])->save();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Identity\Infrastructure\Eloquent\UserModel;

/**
 * How large a person wants the interface to read.
 *
 * Three steps, each one the interface is laid out at: the web scales its whole
 * type and spacing scale from it, so a larger step makes buttons and touch
 * targets larger too, not only the letters. Its own service rather than a
 * second job for InterfaceLanguage, for the reason that one gives — the save
 * belongs in a service, not the controller.
 */
final class TextSize
{
    public const SIZES = ['normal', 'large', 'larger'];

    public function set(UserModel $user, string $size): void
    {
        $user->forceFill(['text_size' => $size])->save();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Announcement\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Something about an announcement that is well-formed and still refused.
 *
 * `details.refusal` names which rule refused, so the interface can be specific
 * without parsing prose.
 */
final class AnnouncementRefused extends DomainException
{
    public function errorCode(): string
    {
        return 'announcement.refused';
    }
}

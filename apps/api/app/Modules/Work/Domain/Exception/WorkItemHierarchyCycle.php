<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * A subtask cannot be its own ancestor. The database catches the one-level case;
 * this catches the rest.
 */
final class WorkItemHierarchyCycle extends DomainException
{
    public function errorCode(): string
    {
        return 'work_item.hierarchy_cycle';
    }
}

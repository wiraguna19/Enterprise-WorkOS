<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Circular blocking. Nothing in the loop could ever start, so the graph must
 * stay acyclic — enforced in the application because Postgres cannot declare it.
 */
final class DependencyCycle extends DomainException
{
    public function errorCode(): string
    {
        return 'work_item.dependency_cycle';
    }
}

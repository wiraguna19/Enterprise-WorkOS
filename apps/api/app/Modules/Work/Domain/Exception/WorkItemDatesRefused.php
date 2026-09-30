<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * A due date before the start date.
 *
 * The table has said so since Phase 2 (a CHECK on `work_items`), and creation
 * said so in its request rules — but an EDIT that moved only one of the two
 * dates was checked by nothing above the database, so it reached Postgres and
 * came back as a 500. A bulk "set due date" over fifty items would have
 * inherited exactly that: the one item with a late start failing the request
 * with a stack trace instead of a sentence.
 */
final class WorkItemDatesRefused extends DomainException
{
    public function errorCode(): string
    {
        return 'work_item.due_before_start';
    }

    public static function dueBeforeStart(string $reference, string $start, string $due): self
    {
        return new self(
            "{$reference} starts on {$start}, so it cannot be due on {$due}. Move the start date first.",
            ['reference' => $reference, 'start_date' => $start, 'due_at' => $due],
        );
    }
}

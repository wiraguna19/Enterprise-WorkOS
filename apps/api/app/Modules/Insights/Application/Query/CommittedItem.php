<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Query;

/**
 * The three fields the workload formula reads off a work item.
 *
 * A typed shape rather than the raw `stdClass` a query builder returns: this
 * class is the definition in docs/02 §11, and "which columns does the number
 * depend on" should be answerable by reading four lines rather than by tracing
 * property access through a generic object. It is also the only way PHPStan can
 * check that a rename in the schema reaches here.
 */
final class CommittedItem
{
    public function __construct(
        public readonly string $id,
        public readonly ?float $estimateHours,
        public readonly ?string $startDate,
        public readonly ?string $dueAt,
    ) {}
}

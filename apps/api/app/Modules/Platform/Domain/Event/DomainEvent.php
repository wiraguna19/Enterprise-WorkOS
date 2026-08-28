<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Event;

/**
 * Marker for events that cross module boundaries.
 *
 * Rules (docs/01-system-architecture.md §5):
 *   - past tense, domain language
 *   - carries IDs and minimal scalar context, never whole models
 *   - recorded inside the transaction, dispatched after commit
 */
interface DomainEvent
{
    public function organizationId(): string;

    /** @return array<string, mixed> */
    public function payload(): array;

    public function occurredAt(): \DateTimeImmutable;
}

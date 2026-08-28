<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Event;

use App\Modules\Platform\Domain\Event\DomainEvent;

/**
 * Events carry IDs and minimal scalar context, never whole models (docs/01 §5).
 *
 * A listener that needs more data queries for it. That keeps the payload
 * serializable for the queue, keeps it small on the wire, and stops events from
 * becoming a back-door coupling to another module's schema.
 */
final readonly class WorkItemCreated implements DomainEvent
{
    public function __construct(
        public string $organizationId,
        public string $workItemId,
        public string $type,
        public ?string $projectId,
        /** Null when the actor is the system: a rule-driven change has no person behind it. */
        public ?string $actorMembershipId,
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable,
    ) {}

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'work_item_id' => $this->workItemId,
            'type' => $this->type,
            'project_id' => $this->projectId,
            'actor_membership_id' => $this->actorMembershipId,
        ];
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

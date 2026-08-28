<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Event;

use App\Modules\Platform\Domain\Event\DomainEvent;

final readonly class WorkItemAssigned implements DomainEvent
{
    public function __construct(
        public string $organizationId,
        public string $workItemId,
        public string $membershipId,
        public string $role,
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
            'membership_id' => $this->membershipId,
            'role' => $this->role,
            'actor_membership_id' => $this->actorMembershipId,
        ];
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

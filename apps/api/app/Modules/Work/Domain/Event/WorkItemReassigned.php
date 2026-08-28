<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Event;

use App\Modules\Platform\Domain\Event\DomainEvent;

/**
 * Distinct from WorkItemAssigned on purpose.
 *
 * A handover is a different thing from a first assignment: different
 * notification wording, different meaning in cycle-time analysis, and the
 * outgoing person needs to know too. Collapsing them into one event would push
 * that branching into every listener.
 */
final readonly class WorkItemReassigned implements DomainEvent
{
    public function __construct(
        public string $organizationId,
        public string $workItemId,
        public string $fromMembershipId,
        public string $toMembershipId,
        public ?string $reason,
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
            'from_membership_id' => $this->fromMembershipId,
            'to_membership_id' => $this->toMembershipId,
            'reason' => $this->reason,
            'actor_membership_id' => $this->actorMembershipId,
        ];
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

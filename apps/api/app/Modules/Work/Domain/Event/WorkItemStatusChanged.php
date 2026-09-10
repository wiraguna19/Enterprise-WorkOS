<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Event;

use App\Modules\Platform\Domain\Event\DomainEvent;

/**
 * The seam between Work and everything downstream (docs/01 §5).
 *
 * Work does not know Workflow, Notification, Search, or Insights exist. It
 * emits this; they subscribe. That is the whole reason a status change has not
 * turned into a 900-line service.
 */
final readonly class WorkItemStatusChanged implements DomainEvent
{
    public function __construct(
        public string $organizationId,
        public string $workItemId,
        public string $fromStateId,
        public string $toStateId,
        public string $toCategory,
        /** Null when the actor is the system: a rule-driven change has no person behind it. */
        public ?string $actorMembershipId,
        public ?string $overrideReason = null,
        /**
         * What the person typed when they moved it, when the edge asked.
         *
         * Carried on the event rather than left in the comment table because
         * the only subscriber that needs it — the rule that opens a review —
         * runs on a queue, after the transaction, with nothing but this
         * payload to go on. Without it the approval's submission note is the
         * empty string every single time in production, and the review queue's
         * one column worth reading is blank.
         */
        public ?string $comment = null,
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
            'from_state_id' => $this->fromStateId,
            'to_state_id' => $this->toStateId,
            'to_category' => $this->toCategory,
            'actor_membership_id' => $this->actorMembershipId,
            'override_reason' => $this->overrideReason,
            'comment' => $this->comment,
        ];
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

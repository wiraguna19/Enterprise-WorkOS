<?php

declare(strict_types=1);

namespace App\Modules\Approval\Domain\Event;

use App\Modules\Platform\Domain\Event\DomainEvent;

/**
 * Notification subscribes to this to tell the reviewers. Approval itself knows
 * nothing about notifications — that is the seam (docs/01 §5).
 */
final readonly class ApprovalOpened implements DomainEvent
{
    /** @param list<string> $reviewerMembershipIds */
    public function __construct(
        public string $organizationId,
        public string $approvalId,
        public string $subjectType,
        public string $subjectId,
        public array $reviewerMembershipIds,
        public string $requestedByMembershipId,
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
            'approval_id' => $this->approvalId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'reviewers' => $this->reviewerMembershipIds,
            'requested_by' => $this->requestedByMembershipId,
        ];
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

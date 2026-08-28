<?php

declare(strict_types=1);

namespace App\Modules\Approval\Domain\Event;

use App\Modules\Platform\Domain\Event\DomainEvent;

/**
 * Carries BOTH the individual decision and the resulting resolution, which are
 * different facts under a quorum policy: the third approver's "approved" is
 * what resolves the approval, and the first two approvers' identical decisions
 * resolved nothing. A listener that only saw `decision` could not tell them
 * apart, and would announce "approved" three times.
 *
 * `resolution` is null while the approval is still pending.
 */
final readonly class ApprovalDecided implements DomainEvent
{
    public function __construct(
        public string $organizationId,
        public string $approvalId,
        public string $subjectType,
        public string $subjectId,
        public string $decision,
        public ?string $resolution,
        public string $reviewerMembershipId,
        public string $requestedByMembershipId,
        public string $comment = '',
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
            'decision' => $this->decision,
            'resolution' => $this->resolution,
            'reviewer' => $this->reviewerMembershipId,
            'requested_by' => $this->requestedByMembershipId,
        ];
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

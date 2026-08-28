<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Listener;

use App\Modules\Approval\Domain\Event\ApprovalDecided;
use App\Modules\Approval\Domain\Event\ApprovalOpened;
use App\Modules\Notification\Application\Service\NotificationDispatcher;
use App\Modules\Work\Domain\Event\WorkItemAssigned;
use App\Modules\Work\Domain\Event\WorkItemReassigned;
use Illuminate\Events\Dispatcher;

/**
 * The seam in action (docs/01 §5).
 *
 * Work and Approval emit events and know nothing about notifications. This
 * subscriber is the only place that decides who hears about what — which is why
 * adding a notification never means touching a domain service.
 *
 * Everything here runs AFTER commit, so a notification can never exist for a
 * change that rolled back.
 */
final class WorkNotificationSubscriber
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(WorkItemAssigned::class, [self::class, 'onAssigned']);
        $events->listen(WorkItemReassigned::class, [self::class, 'onReassigned']);
        $events->listen(ApprovalOpened::class, [self::class, 'onApprovalOpened']);
        $events->listen(ApprovalDecided::class, [self::class, 'onApprovalDecided']);
    }

    public function onAssigned(WorkItemAssigned $event): void
    {
        $this->notifications->dispatch(
            type: 'work.assigned',
            subjectType: 'work_item',
            subjectId: $event->workItemId,
            recipients: [$event->membershipId],
            payload: ['role' => $event->role],
            actorMembershipId: $event->actorMembershipId,
            dedupeSeed: $event->workItemId.':'.$event->membershipId.':'.$event->role,
        );
    }

    /**
     * A handover notifies BOTH people, with different meanings: the incoming
     * person has new work, the outgoing person has lost some. Sending one
     * message to both would be wrong for at least one of them.
     */
    public function onReassigned(WorkItemReassigned $event): void
    {
        $this->notifications->dispatch(
            type: 'work.assigned',
            subjectType: 'work_item',
            subjectId: $event->workItemId,
            recipients: [$event->toMembershipId],
            payload: ['reason' => $event->reason, 'handover' => true],
            actorMembershipId: $event->actorMembershipId,
            dedupeSeed: $event->workItemId.':'.$event->toMembershipId.':handover',
        );

        $this->notifications->dispatch(
            type: 'work.reassigned_away',
            subjectType: 'work_item',
            subjectId: $event->workItemId,
            recipients: [$event->fromMembershipId],
            payload: ['reason' => $event->reason],
            actorMembershipId: $event->actorMembershipId,
            dedupeSeed: $event->workItemId.':'.$event->fromMembershipId.':away',
        );
    }

    public function onApprovalOpened(ApprovalOpened $event): void
    {
        $this->notifications->dispatch(
            type: 'approval.requested',
            subjectType: $event->subjectType,
            subjectId: $event->subjectId,
            recipients: $event->reviewerMembershipIds,
            payload: ['approval_id' => $event->approvalId],
            actorMembershipId: $event->requestedByMembershipId,
            dedupeSeed: $event->approvalId,
        );
    }

    /**
     * Only the RESOLUTION notifies the requester.
     *
     * Under a quorum policy the first two approvals resolve nothing; telling
     * the submitter "approved" three times would be both noisy and wrong.
     */
    public function onApprovalDecided(ApprovalDecided $event): void
    {
        if ($event->resolution === null) {
            return;
        }

        $type = match ($event->resolution) {
            'approved' => 'approval.approved',
            'changes_requested' => 'approval.changes_requested',
            'rejected' => 'approval.rejected',
            default => 'approval.resolved',
        };

        $this->notifications->dispatch(
            type: $type,
            subjectType: $event->subjectType,
            subjectId: $event->subjectId,
            recipients: [$event->requestedByMembershipId],
            payload: ['comment' => $event->comment, 'approval_id' => $event->approvalId],
            actorMembershipId: $event->reviewerMembershipId,
            dedupeSeed: $event->approvalId.':'.$event->resolution,
        );
    }
}

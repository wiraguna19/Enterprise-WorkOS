<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Application\Event\RecordsDomainEvents;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Domain\Event\WorkItemAssigned;
use App\Modules\Work\Domain\Event\WorkItemReassigned;
use App\Modules\Work\Domain\Exception\AlreadyAssigned;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemAssignmentModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;

/**
 * Assignment as an entity with history (docs/02 §6).
 *
 * The rule that governs this whole class: rows are CLOSED, never deleted.
 * Everything valuable — the reassignment narrative, accurate workload history,
 * cycle-time analytics, "who dropped this and why" — falls out of that one
 * decision. A delete anywhere in here would quietly destroy all of it.
 */
final class AssignmentService
{
    use RecordsDomainEvents;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Assign, replacing the current holder of that role if there is one.
     *
     * Replacement is a close + an open in one transaction, so the item is never
     * momentarily unassigned and the handover is a single readable event.
     */
    public function assign(
        WorkItemModel $item,
        string $membershipId,
        string $role = 'assignee',
        ?string $reason = null,
    ): WorkItemAssignmentModel {
        return $this->transactional(function () use ($item, $membershipId, $role, $reason): WorkItemAssignmentModel {
            $membership = MembershipModel::query()->findOrFail($membershipId);

            if (! $membership->isActive()) {
                throw new AlreadyAssigned('That person is no longer active in this organization.');
            }

            $existing = WorkItemAssignmentModel::query()
                ->where('work_item_id', $item->getKey())
                ->where('role', $role)
                ->whereNull('unassigned_at')
                ->lockForUpdate()
                ->get();

            $previousHolder = $existing->firstWhere('is_primary', true);

            if ($existing->contains('membership_id', $membershipId)) {
                throw new AlreadyAssigned('That person already holds this role on this work item.', [
                    'work_item_id' => $item->id,
                    'membership_id' => $membershipId,
                    'role' => $role,
                ]);
            }

            // Close the outgoing row. The reason is part of the record: an
            // unexplained handover is the thing people ask about weeks later.
            if ($previousHolder !== null && $role === 'assignee') {
                $previousHolder->forceFill([
                    'unassigned_at' => now(),
                    'unassigned_reason' => $reason ?? 'Reassigned',
                ])->save();
            }

            $assignment = new WorkItemAssignmentModel;
            $assignment->forceFill([
                'id' => WorkItemAssignmentModel::newId(),
                'work_item_id' => $item->getKey(),
                'membership_id' => $membershipId,
                'role' => $role,
                'is_primary' => $role === 'assignee',
                // Nullable on purpose: a rule-driven assignment runs in a job
                // bound with runFor(), which has an organization and no
                // membership. Asking for one there throws, and the assignment
                // action fails for a reason that has nothing to do with
                // assignment (docs/01 §6).
                'assigned_by_membership_id' => $this->tenant->hasMembership()
                    ? $this->tenant->membershipId()
                    : null,
                'assigned_at' => now(),
            ])->save();

            $actorName = $membership->user === null ? 'Someone' : (string) $membership->user->name;

            if ($previousHolder !== null && $role === 'assignee') {
                $this->activity->record('work_item', (string) $item->getKey(), 'reassigned', [
                    'assignee' => [
                        'from' => $previousHolder->membership_id,
                        'to' => $membershipId,
                    ],
                    'reason' => ['from' => null, 'to' => $reason],
                ]);

                $this->record(new WorkItemReassigned(
                    organizationId: $this->tenant->organizationId(),
                    workItemId: (string) $item->getKey(),
                    fromMembershipId: (string) $previousHolder->membership_id,
                    toMembershipId: $membershipId,
                    reason: $reason,
                    actorMembershipId: $this->actorMembershipId(),
                ));
            } else {
                $this->activity->record('work_item', (string) $item->getKey(), 'assigned', [
                    $role => ['from' => null, 'to' => $actorName],
                ]);

                $this->record(new WorkItemAssigned(
                    organizationId: $this->tenant->organizationId(),
                    workItemId: (string) $item->getKey(),
                    membershipId: $membershipId,
                    role: $role,
                    actorMembershipId: $this->actorMembershipId(),
                ));
            }

            return $assignment;
        });
    }

    /**
     * The assignee acknowledges the work.
     *
     * "Assigned" and "accepted" are different facts. Collapsing them hides the
     * case a manager most needs to see: work that was handed over three days
     * ago and nobody has picked up.
     */
    public function accept(WorkItemModel $item, string $membershipId): WorkItemAssignmentModel
    {
        return $this->transactional(function () use ($item, $membershipId): WorkItemAssignmentModel {
            /** @var WorkItemAssignmentModel $assignment */
            $assignment = WorkItemAssignmentModel::query()
                ->where('work_item_id', $item->getKey())
                ->where('membership_id', $membershipId)
                ->where('role', 'assignee')
                ->whereNull('unassigned_at')
                ->firstOrFail();

            if ($assignment->accepted_at !== null) {
                return $assignment;
            }

            $assignment->forceFill(['accepted_at' => now()])->save();

            $this->activity->record('work_item', (string) $item->getKey(), 'accepted');

            return $assignment;
        });
    }

    /**
     * Remove someone from a work item.
     *
     * Closes the row; there is no delete path. After this the item has no
     * active assignee, which is a legitimate state — unassigned work must
     * surface on dashboards rather than being impossible to represent.
     */
    public function unassign(WorkItemModel $item, string $assignmentId, ?string $reason = null): void
    {
        $this->transactional(function () use ($item, $assignmentId, $reason): void {
            /** @var WorkItemAssignmentModel $assignment */
            $assignment = WorkItemAssignmentModel::query()
                ->where('work_item_id', $item->getKey())
                ->whereNull('unassigned_at')
                ->findOrFail($assignmentId);

            $assignment->forceFill([
                'unassigned_at' => now(),
                'unassigned_reason' => $reason ?? 'Removed',
            ])->save();

            $this->activity->record('work_item', (string) $item->getKey(), 'unassigned', [
                $assignment->role => ['from' => $assignment->membership_id, 'to' => null],
            ]);
        });
    }

    /**
     * The full narrative for one item, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(WorkItemModel $item): array
    {
        return array_values(
            WorkItemAssignmentModel::query()
                ->with('membership.user', 'assignedBy.user')
                ->where('work_item_id', $item->getKey())
                ->orderBy('assigned_at')
                ->get()
                ->map(fn (WorkItemAssignmentModel $a) => [
                    'id' => $a->id,
                    'role' => $a->role,
                    'person' => $a->membership?->user?->name,
                    'assigned_by' => $a->assignedBy?->user?->name,
                    'assigned_at' => $a->assigned_at,
                    'accepted_at' => $a->accepted_at,
                    'unassigned_at' => $a->unassigned_at,
                    'reason' => $a->unassigned_reason,
                    'active' => $a->isActive(),
                ])
                ->all()
        );
    }

    /**
     * The person behind this change, or null when there is none.
     *
     * A rule-driven change runs in a job bound with runFor(): it has an
     * organization and no membership, and asking for one there throws. The
     * events carry null rather than a borrowed identity — attributing an
     * automated action to whoever happened to trigger it is how an audit trail
     * starts lying (docs/01 §6).
     */
    private function actorMembershipId(): ?string
    {
        return $this->tenant->hasMembership() ? $this->tenant->membershipId() : null;
    }
}

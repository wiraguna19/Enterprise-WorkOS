<?php

declare(strict_types=1);

namespace App\Modules\Approval\Application\Service;

use App\Modules\Approval\Domain\Event\ApprovalDecided;
use App\Modules\Approval\Domain\Event\ApprovalOpened;
use App\Modules\Approval\Domain\Exception\AlreadyPending;
use App\Modules\Approval\Domain\Exception\NotAnApprover;
use App\Modules\Approval\Domain\Exception\SelfReviewRefused;
use App\Modules\Approval\Infrastructure\Eloquent\ApprovalModel;
use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Governance\Application\Service\SettingResolver;
use App\Modules\Platform\Application\Event\RecordsDomainEvents;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Approvals (docs/02 §4.3).
 *
 * The invariants this class exists to hold:
 *
 *   - one PENDING approval per subject, so "which decision wins" never arises
 *   - decisions are APPEND-ONLY; a reversal is a new record, never an edit
 *   - the requester may not decide their own submission unless the
 *     organization has explicitly turned that on
 *   - a quorum resolves exactly once, under a lock, so two reviewers deciding
 *     simultaneously cannot both believe they cast the deciding vote
 */
final class ApprovalService
{
    use RecordsDomainEvents;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly SettingResolver $settings,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Open a review request.
     *
     * @param  list<string>  $reviewerMembershipIds
     */
    public function open(
        string $subjectType,
        string $subjectId,
        array $reviewerMembershipIds,
        string $policy = 'any_one',
        int $requiredApprovals = 1,
        string $note = '',
        ?string $requestedBy = null,
    ): ApprovalModel {
        return $this->transactional(function () use (
            $subjectType, $subjectId, $reviewerMembershipIds,
            $policy, $requiredApprovals, $note, $requestedBy,
        ): ApprovalModel {
            // The column is nullable, and so is this: an approval opened by a
            // rule has no requester, and inventing one would put a person's
            // name on a decision they never made (docs/01 §6).
            $requester = $requestedBy ?? ($this->tenant->hasMembership()
                ? $this->tenant->membershipId()
                : null);

            $pending = ApprovalModel::query()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('status', 'pending')
                ->exists();

            if ($pending) {
                throw new AlreadyPending('This work is already awaiting a decision.', [
                    'subject_id' => $subjectId,
                ]);
            }

            $reviewers = array_values(array_unique($reviewerMembershipIds));

            if (! $this->selfReviewAllowed()) {
                $withoutRequester = array_values(array_diff($reviewers, [$requester]));

                // If the requester was the ONLY reviewer, removing them would
                // leave an approval nobody can decide — which sits pending
                // forever and silently blocks the workflow. Refusing is the
                // honest failure.
                if ($withoutRequester === [] && $reviewers !== []) {
                    throw new SelfReviewRefused(
                        'You cannot review your own submission, and no other reviewer is assigned.',
                        ['setting' => 'approvals.allow_self_review'],
                    );
                }

                $reviewers = $withoutRequester;
            }

            // quorum cannot demand more approvals than there are approvers.
            $required = match ($policy) {
                'all_of' => max(count($reviewers), 1),
                'quorum' => min(max($requiredApprovals, 1), max(count($reviewers), 1)),
                default => 1,
            };

            $approval = new ApprovalModel;
            $id = (string) new UuidV7;

            $approval->forceFill([
                'id' => $id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'requested_by_membership_id' => $requester,
                'status' => 'pending',
                'policy' => $policy,
                'required_approvals' => $required,
                'submission_note' => $note,
                'submitted_at' => now(),
            ])->save();

            foreach ($reviewers as $reviewer) {
                DB::table('approval_approvers')->insert([
                    'id' => (string) new UuidV7,
                    'organization_id' => $this->tenant->organizationId(),
                    'approval_id' => $id,
                    'membership_id' => $reviewer,
                    'created_at' => now(),
                ]);
            }

            $this->activity->record($subjectType, $subjectId, 'submitted_for_review', [
                'approval_id' => ['from' => null, 'to' => $id],
            ]);

            $this->record(new ApprovalOpened(
                organizationId: $this->tenant->organizationId(),
                approvalId: $id,
                subjectType: $subjectType,
                subjectId: $subjectId,
                reviewerMembershipIds: $reviewers,
                requestedByMembershipId: (string) $requester,
            ));

            return $approval;
        });
    }

    /**
     * Record a decision, and resolve the approval if the policy is satisfied.
     *
     * The row lock is not defensive decoration: with an `all_of` or `quorum`
     * policy, two reviewers deciding at the same instant would otherwise both
     * read "one approval so far" and both conclude the threshold was not met —
     * leaving an approval that everyone approved and nothing resolved.
     */
    public function decide(
        ApprovalModel $approval,
        string $decision,
        string $comment = '',
    ): ApprovalModel {
        return $this->transactional(function () use ($approval, $decision, $comment): ApprovalModel {
            /** @var ApprovalModel $locked */
            $locked = ApprovalModel::query()->lockForUpdate()->findOrFail($approval->getKey());

            $reviewer = $this->tenant->membershipId();

            if ($locked->status !== 'pending') {
                throw new AlreadyPending('This approval has already been resolved.', [
                    'status' => $locked->status,
                ]);
            }

            $this->assertMayDecide($locked, $reviewer);

            DB::table('approval_decisions')->insert([
                'id' => (string) new UuidV7,
                'organization_id' => $this->tenant->organizationId(),
                'approval_id' => $locked->getKey(),
                'reviewer_membership_id' => $reviewer,
                'decision' => $decision,
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            $resolution = $this->evaluatePolicy($locked, $decision);

            if ($resolution !== null) {
                $locked->forceFill([
                    'status' => $resolution,
                    'resolved_at' => now(),
                    'lock_version' => $locked->lock_version + 1,
                ])->save();
            }

            $this->activity->record(
                $locked->subject_type,
                (string) $locked->subject_id,
                'review_'.$decision,
                ['decision' => ['from' => null, 'to' => $decision]],
            );

            $this->record(new ApprovalDecided(
                organizationId: $this->tenant->organizationId(),
                approvalId: (string) $locked->getKey(),
                subjectType: $locked->subject_type,
                subjectId: (string) $locked->subject_id,
                decision: $decision,
                resolution: $resolution,
                reviewerMembershipId: $reviewer,
                requestedByMembershipId: (string) $locked->requested_by_membership_id,
                comment: $comment,
            ));

            return $locked;
        });
    }

    /** The requester withdrawing their own submission. */
    public function withdraw(ApprovalModel $approval, string $reason = ''): ApprovalModel
    {
        return $this->transactional(function () use ($approval, $reason): ApprovalModel {
            /** @var ApprovalModel $locked */
            $locked = ApprovalModel::query()->lockForUpdate()->findOrFail($approval->getKey());

            if ($locked->status !== 'pending') {
                throw new AlreadyPending('This approval has already been resolved.');
            }

            $locked->forceFill([
                'status' => 'withdrawn',
                'resolved_at' => now(),
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            $this->activity->record(
                $locked->subject_type,
                (string) $locked->subject_id,
                'review_withdrawn',
                ['reason' => ['from' => null, 'to' => $reason]],
            );

            return $locked;
        });
    }

    /**
     * Has the policy been satisfied?
     *
     * @return string|null the resolved status, or null if still pending
     */
    private function evaluatePolicy(ApprovalModel $approval, string $latestDecision): ?string
    {
        // A rejection or a changes-request resolves immediately regardless of
        // policy. Waiting for the remaining approvers after someone has said
        // "this is wrong" wastes their time and delays the fix.
        if ($latestDecision !== 'approved') {
            return $latestDecision;
        }

        $approvals = DB::table('approval_decisions')
            ->where('approval_id', $approval->getKey())
            ->where('decision', 'approved')
            ->distinct()
            ->count('reviewer_membership_id');

        return $approvals >= $approval->required_approvals ? 'approved' : null;
    }

    private function assertMayDecide(ApprovalModel $approval, string $reviewer): void
    {
        if ((string) $approval->requested_by_membership_id === $reviewer
            && ! $this->selfReviewAllowed()) {
            throw new SelfReviewRefused('You cannot review your own submission.', [
                'setting' => 'approvals.allow_self_review',
            ]);
        }

        $isApprover = DB::table('approval_approvers')
            ->where('approval_id', $approval->getKey())
            ->where('membership_id', $reviewer)
            ->exists();

        // `approval.decide_any` is checked by the policy layer, which lets an
        // admin unblock a stalled review. This guards the ordinary path.
        if (! $isApprover) {
            throw new NotAnApprover('You were not asked to review this.', [
                'approval_id' => $approval->id,
            ]);
        }
    }

    private function selfReviewAllowed(): bool
    {
        return (bool) $this->settings->get('approvals.allow_self_review', false);
    }
}

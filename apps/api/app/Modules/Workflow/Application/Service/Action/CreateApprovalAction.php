<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service\Action;

use App\Modules\Approval\Application\Service\ApprovalService;
use App\Modules\Work\Application\Service\AssignmentService;
use App\Modules\Work\Domain\Exception\AlreadyAssigned;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\DB;

/**
 * Open a review request automatically.
 *
 * This is the action that makes "when work enters In Review, create an approval
 * for the reviewer" work — the flow docs/02 §6 describes, expressed as
 * configuration rather than as a hardcoded branch in the transition service.
 *
 * Idempotent through the schema: one PENDING approval per subject is a partial
 * unique index, so a redelivered job cannot open a second one.
 */
final class CreateApprovalAction implements WorkflowAction
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly AssignmentService $assignments,
    ) {}

    /** {@inheritDoc} */
    public function execute(
        array $config,
        string $subjectType,
        string $subjectId,
        array $facts,
        string $causationId,
        int $depth,
    ): array {
        $existing = DB::table('approvals')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            return ['skipped' => 'an approval is already pending'];
        }

        $reviewers = $this->resolveReviewers($config, $facts, $subjectId);

        if ($reviewers === []) {
            // Better to leave the work visibly un-reviewed than to open an
            // approval nobody is asked to decide: an approval with no approver
            // sits pending forever and silently blocks the workflow.
            return ['skipped' => 'no reviewer resolved'];
        }

        // Whoever is asked to decide becomes a reviewer ON THE ITEM as well.
        //
        // The two are separate tables answering the same question, and letting
        // them disagree is what strands work: the transition guards read the
        // item's assignment roles, so an approver who is not also a reviewer
        // there can decide the approval and still be refused the move it
        // implies. Falling back to the project owner (below) is exactly the
        // case where nobody would otherwise hold the role.
        $this->ensureReviewerRole($subjectType, $subjectId, $reviewers);

        $approval = $this->approvals->open(
            subjectType: $subjectType,
            subjectId: $subjectId,
            reviewerMembershipIds: $reviewers,
            policy: (string) ($config['policy'] ?? 'any_one'),
            requiredApprovals: (int) ($config['required_approvals'] ?? 1),
            note: (string) ($config['note'] ?? ''),
            requestedBy: $facts['assignee_membership_id'] ?? null,
        );

        return ['approval_id' => $approval->id, 'reviewers' => count($reviewers)];
    }

    /**
     * @param  list<string>  $reviewers
     */
    private function ensureReviewerRole(string $subjectType, string $subjectId, array $reviewers): void
    {
        if ($subjectType !== 'work_item') {
            return;
        }

        /** @var WorkItemModel|null $item */
        $item = WorkItemModel::query()->find($subjectId);

        if ($item === null) {
            return;
        }

        foreach ($reviewers as $membershipId) {
            try {
                $this->assignments->assign($item, $membershipId, 'reviewer');
            } catch (AlreadyAssigned) {
                // Already holds the role: nothing to do, and nothing to report.
                // This action is redelivered by the queue by design.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    private function resolveReviewers(array $config, array $facts, string $subjectId): array
    {
        $from = (string) ($config['reviewers'] ?? 'assigned_reviewers');

        if ($from === 'assigned_reviewers') {
            /** @var list<string> $reviewers */
            $reviewers = DB::table('work_item_assignments')
                ->where('work_item_id', $subjectId)
                ->whereIn('role', ['reviewer', 'approver'])
                ->whereNull('unassigned_at')
                ->pluck('membership_id')
                ->all();

            if ($reviewers !== []) {
                return $reviewers;
            }
        }

        // Falls back to the project owner so a project with no named reviewer
        // still routes somewhere a human will see.
        $owner = isset($facts['project_id'])
            ? DB::table('projects')->where('id', $facts['project_id'])->value('owner_membership_id')
            : null;

        return $owner === null ? [] : [(string) $owner];
    }
}

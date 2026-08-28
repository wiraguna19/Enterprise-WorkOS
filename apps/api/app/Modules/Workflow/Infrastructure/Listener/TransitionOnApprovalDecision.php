<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Listener;

use App\Modules\Approval\Domain\Event\ApprovalDecided;
use App\Modules\Work\Application\Service\WorkItemService;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowStateModel;
use Illuminate\Support\Facades\Log;

/**
 * A resolved approval moves the work it was about.
 *
 * Without this the two halves of one act drift apart: the reviewer records
 * "changes requested" and the item stays parked in In Review, where the only
 * edge back to In Progress is guarded to reviewers — so the person whose work
 * was bounced cannot resume it. The approval says one thing and the board says
 * another, and the board is what people act on.
 *
 * Deliberately NOT a queued job. It runs in the reviewer's own request, so the
 * transition is attributed to them and the graph's guard sees the reviewer it
 * expects. A background job would arrive as `system`, hold none of the roles
 * the edge names, and be refused by the very guard that makes the decision
 * meaningful.
 *
 * It lives in Workflow because Workflow is the only module allowed to know
 * about both Work and Approval (docs/04 §3). Approval must not reach into Work
 * to do this itself.
 */
final class TransitionOnApprovalDecision
{
    /**
     * Resolution → the state key it lands on.
     *
     * Keys, not categories: a workflow that has no `approved` state simply does
     * not take part, which is what makes a second workflow with a different
     * shape possible (docs/02 §7).
     *
     * `rejected` lands on `cancelled` (ADR 0005). A rejection means the work
     * should not continue — that is what separates it from a changes request,
     * which sends the same work back to be finished. Before this existed the
     * approval resolved and the item stayed parked in In Review, where the only
     * edge out is reviewer-guarded: the person whose work was rejected could
     * neither continue it nor close it.
     */
    private const LANDING_STATE = [
        'approved' => 'approved',
        'changes_requested' => 'in_progress',
        'rejected' => 'cancelled',
    ];

    public function __construct(
        private readonly WorkItemService $workItems,
    ) {}

    public function handle(ApprovalDecided $event): void
    {
        if ($event->subjectType !== 'work_item' || $event->resolution === null) {
            return;
        }

        $targetKey = self::LANDING_STATE[$event->resolution] ?? null;

        if ($targetKey === null) {
            return;
        }

        /** @var WorkItemModel|null $item */
        $item = WorkItemModel::query()->find($event->subjectId);

        if ($item === null) {
            return;
        }

        $target = WorkflowStateModel::query()
            ->where('workflow_id', $item->workflow_id)
            ->where('key', $targetKey)
            ->first();

        if ($target === null || (string) $item->workflow_state_id === (string) $target->getKey()) {
            return;
        }

        // The decision's comment carries over: the edge back from review
        // requires one, and the reason the reviewer already gave IS that
        // reason. Asking for it twice is how the second copy ends up empty.
        $options = ['comment' => $event->comment];

        // A rejection closes work that may still have open blockers, and
        // closing blocked work needs an explicit override with a reason
        // (docs/02 §4.2). That is exactly what a rejection is — a person with
        // the authority to decide, saying why — so the override is recorded
        // rather than the invariant weakened. Without this, rejecting anything
        // that happened to be blocked would fail with a conflict the reviewer
        // could do nothing about.
        if ($event->resolution === 'rejected') {
            $options['override_reason'] = trim($event->comment) !== ''
                ? 'Rejected in review: '.$event->comment
                : 'Rejected in review.';
        }

        $this->workItems->transition($item, (string) $target->getKey(), $options);

        Log::info('approval.decision_moved_work', [
            'approval_id' => $event->approvalId,
            'work_item_id' => $event->subjectId,
            'to_state' => $targetKey,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controller;

use App\Modules\Approval\Application\Service\ApprovalService;
use App\Modules\Approval\Http\Resource\ApprovalResource;
use App\Modules\Approval\Infrastructure\Eloquent\ApprovalModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ApprovalController extends ApiController
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * The reviewer's queue and the requester's "waiting on others" — two views
     * of the same table, because they are genuinely the same question asked
     * from two sides.
     */
    public function index(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'role' => ['sometimes', Rule::in(['reviewer', 'requester'])],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'changes_requested', 'rejected', 'withdrawn'])],
        ]);

        $role = $validated['role'] ?? 'reviewer';
        $membershipId = $this->tenant->membershipId();

        $approvals = ApprovalModel::query()
            ->with(['requester.user:id,name', 'approvers.membership.user:id,name', 'decisions.reviewer.user:id,name'])
            ->when(
                $role === 'reviewer',
                fn ($q) => $q->whereExists(fn ($sub) => $sub
                    ->from('approval_approvers')
                    ->whereColumn('approval_approvers.approval_id', 'approvals.id')
                    ->where('membership_id', $membershipId)),
                fn ($q) => $q->where('requested_by_membership_id', $membershipId),
            )
            ->where('status', $validated['status'] ?? 'pending')
            ->orderBy('submitted_at')
            ->get();

        return $this->ok(ApprovalResource::collection($this->withSubjects($approvals)));
    }

    public function show(string $id): ApiResponse
    {
        $approval = ApprovalModel::query()
            ->with(['requester.user:id,name', 'approvers.membership.user:id,name', 'decisions.reviewer.user:id,name'])
            ->findOrFail($id);

        $this->authorize('view', $approval);

        return $this->ok(new ApprovalResource($this->withSubjects(collect([$approval]))->first()));
    }

    /**
     * Decide. The only write on this resource that matters, and the reason
     * every other rule in ApprovalService exists.
     */
    public function decide(Request $request, string $id): ApiResponse
    {
        /** @var ApprovalModel $approval */
        $approval = ApprovalModel::query()->findOrFail($id);

        $this->authorize('decide', $approval);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'changes_requested', 'rejected'])],
            // Required for anything but approval: bouncing work back without
            // saying why just sends it around the loop again. The database
            // enforces this too — belt and braces, because the API is not the
            // only writer.
            // `nullable` matters: ConvertEmptyStringsToNull turns an explicitly
            // empty comment into null before validation, and without it an
            // approval sent with "comment": "" is rejected as a type error
            // rather than accepted as what it is — no comment.
            'comment' => ['required_unless:decision,approved', 'nullable', 'string', 'max:5000'],
        ]);

        $decided = $this->approvals->decide(
            $approval,
            $validated['decision'],
            $validated['comment'] ?? '',
        );

        return $this->ok(new ApprovalResource(
            $decided->fresh(['requester.user', 'approvers.membership.user', 'decisions.reviewer.user'])
        ));
    }

    public function withdraw(Request $request, string $id): ApiResponse
    {
        /** @var ApprovalModel $approval */
        $approval = ApprovalModel::query()->findOrFail($id);

        $this->authorize('withdraw', $approval);

        $this->approvals->withdraw($approval, (string) $request->input('reason', ''));

        return $this->noContent();
    }

    /**
     * Attach the subject each approval is about.
     *
     * One query for all of them rather than one per approval: the review queue
     * renders the work item reference and title for every row, and doing it
     * lazily is the textbook N+1 (docs/11 §3).
     */
    /**
     * @param  Collection<int, ApprovalModel>  $approvals
     * @return Collection<int, ApprovalModel>
     */
    private function withSubjects(Collection $approvals): Collection
    {
        $workItemIds = $approvals
            ->where('subject_type', 'work_item')
            ->pluck('subject_id')
            ->unique();

        $items = $workItemIds->isEmpty()
            ? collect()
            : DB::table('work_items')
                ->whereIn('id', $workItemIds)
                ->get(['id', 'reference', 'title', 'state_category', 'priority'])
                ->keyBy('id');

        return $approvals->each(function (ApprovalModel $approval) use ($items): void {
            $approval->setAttribute('subject', $items->get($approval->subject_id));
        });
    }
}

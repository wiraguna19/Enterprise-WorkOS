<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Application\Service\AssignmentService;
use App\Modules\Work\Http\Resource\WorkItemResource;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Http\Request;

final class AssignmentController extends ApiController
{
    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly WorkItemVisibility $visibility,
        private readonly TenantContext $tenant,
    ) {}

    public function store(Request $request, string $reference): ApiResponse
    {
        $item = $this->findVisible($reference);

        $this->authorize('assign', $item);

        $validated = $request->validate([
            'membership_id' => ['required', 'uuid'],
            'role' => ['sometimes', 'in:assignee,reviewer,approver,watcher,collaborator'],
            'reason' => ['sometimes', 'string', 'max:200'],
        ]);

        $this->assignments->assign(
            $item,
            $validated['membership_id'],
            $validated['role'] ?? 'assignee',
            $validated['reason'] ?? null,
        );

        return $this->ok(new WorkItemResource(
            $item->fresh(['state', 'project:id,key,name', 'assignments.membership.user'])
        ));
    }

    /**
     * The assignee acknowledges the work.
     *
     * Anyone can accept work assigned to THEM; nobody can accept on someone
     * else's behalf, which is why this checks identity rather than a permission.
     */
    public function accept(string $reference): ApiResponse
    {
        $item = $this->findVisible($reference);

        $this->assignments->accept($item, $this->tenant->membershipId());

        return $this->ok(new WorkItemResource(
            $item->fresh(['state', 'project:id,key,name', 'assignments.membership.user'])
        ));
    }

    public function destroy(Request $request, string $reference, string $assignment): ApiResponse
    {
        $item = $this->findVisible($reference);

        $this->authorize('assign', $item);

        $this->assignments->unassign($item, $assignment, $request->input('reason'));

        return $this->noContent();
    }

    /**
     * The narrative: who had this, who handed it over, when, and why.
     *
     * This endpoint is the whole reason assignment is an entity rather than a
     * pivot table (docs/02 §6).
     */
    public function history(string $reference): ApiResponse
    {
        $item = $this->findVisible($reference);

        $this->authorize('view', $item);

        return $this->ok($this->assignments->history($item));
    }

    private function findVisible(string $reference): WorkItemModel
    {
        $query = WorkItemModel::query()->where('reference', mb_strtoupper($reference));

        $this->visibility->apply($query);

        return $query->firstOrFail();
    }
}

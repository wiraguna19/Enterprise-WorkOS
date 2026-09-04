<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Platform\Application\Query\CursorPage;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Application\Service\WorkItemService;
use App\Modules\Work\Http\Request\CreateWorkItemRequest;
use App\Modules\Work\Http\Request\ListWorkItemsRequest;
use App\Modules\Work\Http\Request\UpdateWorkItemRequest;
use App\Modules\Work\Http\Resource\WorkItemResource;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Http\Request;

final class WorkItemController extends ApiController
{
    public function __construct(
        private readonly WorkItemService $workItems,
        private readonly WorkItemVisibility $visibility,
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
    ) {}

    public function index(ListWorkItemsRequest $request): ApiResponse
    {
        $query = WorkItemModel::query()
            ->with(['state', 'project:id,key,name', 'assignments.membership.user:id,name,avatar_path'])
            ->withCount('children')
            ->whereNull('deleted_at');

        // Applied before any filter, so no filter can widen what is visible.
        $this->visibility->apply($query);

        $request->applyFilters($query);

        $page = new CursorPage(
            $query->cursorPaginate(CursorPage::perPage($request->integer('limit')))
        );

        return ApiResponse::collection(
            WorkItemResource::collection($page->paginator->items()),
            $page->meta(),
        );
    }

    public function show(string $reference): ApiResponse
    {
        $item = $this->findVisible($reference);

        $this->authorize('view', $item);

        $item->load([
            'state', 'project', 'milestone', 'parent:id,reference,title',
            'children.state', 'assignmentHistory.membership.user',
            'dependencies.dependsOn:id,reference,title,state_category',
            'dependents.workItem:id,reference,title,state_category',
        ]);

        return $this->ok(new WorkItemResource($item));
    }

    public function store(CreateWorkItemRequest $request): ApiResponse
    {
        $item = $this->workItems->create($request->validated());

        $item->load(['state', 'project:id,key,name', 'assignments.membership.user']);

        return $this->created(new WorkItemResource($item));
    }

    public function update(UpdateWorkItemRequest $request, string $reference): ApiResponse
    {
        $item = $this->findVisible($reference);

        $this->authorize('update', $item);

        $updated = $this->workItems->update(
            $item,
            $request->safe()->except('lock_version'),
            // has(), not `?: null`: version 0 is a real version — the one every
            // freshly created row has — and `?:` folded it into "no version
            // sent", which silently disabled optimistic locking for the first
            // edit of every work item (docs/03 §8).
            $request->has('lock_version') ? $request->integer('lock_version') : null,
        );

        $updated->load(['state', 'project:id,key,name', 'assignments.membership.user']);

        return $this->ok(new WorkItemResource($updated));
    }

    /**
     * Status changes are a sub-resource ACTION, not a PATCH of a status field.
     *
     * Submitting, approving, and moving are domain operations with rules and
     * side effects; pretending they are field edits pushes workflow logic into
     * the client (docs/05 §1).
     */
    public function transition(Request $request, string $reference): ApiResponse
    {
        $item = $this->findVisible($reference);

        $this->authorize('transition', $item);

        $validated = $request->validate([
            'to_state_id' => ['required', 'uuid'],
            'lock_version' => ['sometimes', 'integer'],
            'override_reason' => ['sometimes', 'string', 'max:200'],
            // Some edges require one ("Request changes" without saying what to
            // change is a silent rejection). Whether it is mandatory is decided
            // by the TRANSITION row, not here — the graph is configuration.
            'comment' => ['sometimes', 'string', 'max:2000'],
        ]);

        if (isset($validated['override_reason'])) {
            // Overriding a blocked close is a separate, higher trust level.
            $this->authorize('overrideTransition', $item);
        }

        $updated = $this->workItems->transition($item, $validated['to_state_id'], $validated);

        $updated->load(['state', 'project:id,key,name', 'assignments.membership.user']);

        return $this->ok(new WorkItemResource($updated));
    }

    /**
     * Reorder on the board, and optionally land in another column.
     *
     * The second half is why this needs two authorizations. A move carrying a
     * `to_state_id` performs a real transition — `WorkItemService::move()`
     * delegates to `transition()`, guards and all — so checking only `update`
     * let anyone holding `work_item.update` change state without holding
     * `work_item.transition`. The workflow guards still ran, which is why this
     * never looked like a hole: the move was legal by the graph and merely
     * taken by someone the policy would have refused at the endpoint next door.
     *
     * Two endpoints reaching one behaviour must ask the same question of the
     * caller, or the weaker one becomes the way in.
     */
    public function move(Request $request, string $reference): ApiResponse
    {
        $item = $this->findVisible($reference);

        $this->authorize('update', $item);

        $validated = $request->validate([
            'before_id' => ['sometimes', 'nullable', 'uuid'],
            'after_id' => ['sometimes', 'nullable', 'uuid'],
            'to_state_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        // Compared against the item's current state rather than merely being
        // present: a board that posts the column it dropped into always sends
        // one, and a same-column reorder is not a transition.
        if (isset($validated['to_state_id']) && $validated['to_state_id'] !== $item->workflow_state_id) {
            // Both layers, because both apply to the endpoint next door: the
            // route's `permission:` middleware (layer 3, docs/06 §2) and the
            // policy (layer 4). The route cannot declare the first — this same
            // endpoint reorders a column, which needs no such permission — so
            // the request body is what decides, and the check moves here.
            $membership = $this->acting->get();

            if ($membership === null || ! $this->permissions->has($membership, 'work_item.transition')) {
                abort(403, 'You do not have permission to perform this action.');
            }

            $this->authorize('transition', $item);
        }

        $moved = $this->workItems->move(
            $item,
            $validated['before_id'] ?? null,
            $validated['after_id'] ?? null,
            $validated['to_state_id'] ?? null,
        );

        $moved->load(['state', 'project:id,key,name', 'assignments.membership.user']);

        return $this->ok(new WorkItemResource($moved));
    }

    public function destroy(string $reference): ApiResponse
    {
        $item = $this->findVisible($reference);

        $this->authorize('delete', $item);

        // Soft delete: restoring work someone removed by mistake is a real
        // support request, and the activity trail must survive it (docs/03 §0).
        $item->delete();

        return $this->noContent();
    }

    /**
     * Lookup by human reference (ENG-142), scoped to what the actor may see.
     *
     * A work item that exists but is not visible returns 404, never 403: a 403
     * confirms it exists (docs/05 §3).
     */
    private function findVisible(string $reference): WorkItemModel
    {
        $query = WorkItemModel::query()->where('reference', mb_strtoupper($reference));

        $this->visibility->apply($query);

        return $query->firstOrFail();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Http\Resource\ProjectResource;
use App\Modules\Work\Http\Resource\WorkItemResource;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

final class ProjectController extends ApiController
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): ApiResponse
    {
        $projects = $this->visibleProjects()
            ->with(['owner.user:id,name', 'department:id,name'])
            ->withCount('members')
            // Counted in the same query rather than per row: the directory
            // renders both numbers for every project, and doing it lazily is a
            // textbook N+1 (docs/11 §3).
            ->addSelect([
                'open_work_count' => DB::table('work_items')
                    ->selectRaw('count(*)')
                    ->whereColumn('work_items.project_id', 'projects.id')
                    ->whereNull('work_items.deleted_at')
                    ->whereNotIn('work_items.state_category', ['done', 'cancelled']),
                'overdue_work_count' => DB::table('work_items')
                    ->selectRaw('count(*)')
                    ->whereColumn('work_items.project_id', 'projects.id')
                    ->whereNull('work_items.deleted_at')
                    ->whereNotIn('work_items.state_category', ['done', 'cancelled'])
                    ->whereNotNull('work_items.due_at')
                    ->whereRaw('work_items.due_at < now()'),
            ])
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->active())
            ->when($request->filled('filter.status'), fn ($q) => $q
                ->where('status', $request->input('filter.status')))
            ->orderBy('name')
            ->get();

        return $this->ok(ProjectResource::collection($projects));
    }

    public function show(string $key): ApiResponse
    {
        $project = $this->visibleProjects()
            ->with(['owner.user:id,name', 'department:id,name', 'milestones'])
            ->where('key', mb_strtoupper($key))
            ->firstOrFail();

        $this->authorize('view', $project);

        return $this->ok(new ProjectResource($project));
    }

    /**
     * Board data: one query for the columns, one for the cards.
     *
     * Deliberately NOT one query per column — a seven-column board would then
     * cost seven round trips before a single card renders.
     */
    public function board(string $key): ApiResponse
    {
        $project = $this->visibleProjects()->where('key', mb_strtoupper($key))->firstOrFail();

        $this->authorize('view', $project);

        $states = DB::table('workflow_states')
            ->where('workflow_id', $project->workflow_id)
            ->orderBy('position')
            ->get(['id', 'key', 'label', 'category', 'color', 'position']);

        $items = WorkItemModel::query()
            ->with(['state', 'assignments.membership.user:id,name,avatar_path'])
            ->withCount('children')
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get();

        return $this->ok([
            'project' => new ProjectResource($project),
            'columns' => $states->map(fn ($state) => [
                'state' => $state,
                'items' => WorkItemResource::collection(
                    $items->where('workflow_state_id', $state->id)->values()
                ),
            ])->values(),
        ]);
    }

    public function store(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9]{1,11}$/'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'string', 'max:20000'],
            'department_id' => ['sometimes', 'nullable', 'uuid'],
            'visibility' => ['sometimes', 'in:internal,private'],
            'priority' => ['sometimes', 'in:low,medium,high,urgent'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $project = DB::transaction(function () use ($validated): ProjectModel {
            $project = new ProjectModel;
            $id = ProjectModel::newId();

            $project->forceFill($validated + [
                'id' => $id,
                'owner_membership_id' => $this->tenant->membershipId(),
                'workflow_id' => DB::table('workflows')
                    ->where('organization_id', $this->tenant->organizationId())
                    ->where('applies_to_type', 'task')
                    ->where('is_default', true)
                    ->value('id'),
            ])->save();

            // The creator is a member. Creating a project you cannot then see
            // is the kind of bug that only shows up in production.
            DB::table('project_members')->insert([
                'id' => (string) new UuidV7,
                'organization_id' => $this->tenant->organizationId(),
                'project_id' => $id,
                'membership_id' => $this->tenant->membershipId(),
                'role' => 'owner',
                'added_at' => now(),
            ]);

            return $project;
        });

        return $this->created(new ProjectResource($project));
    }

    /** @return Builder<ProjectModel> */
    private function visibleProjects()
    {
        /** @var MembershipModel $actor */
        $actor = $this->acting->getOrFail();

        return ProjectModel::query()->visibleTo(
            $this->tenant->membershipId(),
            $this->permissions->has($actor, 'project.view_all'),
        );
    }
}

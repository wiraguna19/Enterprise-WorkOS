<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Request\OnlyKnownFilters;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Service\ProjectService;
use App\Modules\Work\Http\Resource\ProjectResource;
use App\Modules\Work\Http\Resource\WorkItemResource;
use App\Modules\Work\Infrastructure\Eloquent\ProjectMemberModel;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\Uid\UuidV7;

final class ProjectController extends ApiController
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
        private readonly ProjectService $projects,
    ) {}

    public function index(Request $request): ApiResponse
    {
        // The whitelist docs/05 §4 promises. This endpoint read `filter.*`
        // straight off the request, so an unknown key was ignored — and the
        // status list was never checked against the CHECK constraint that
        // decides it, so `filter[status]=activ` returned an empty project list
        // and looked like an organization with no projects.
        $request->validate([
            'filter' => ['sometimes', 'array', new OnlyKnownFilters(['status'])],
            'filter.status' => ['sometimes', Rule::in(ProjectModel::STATUSES)],
        ]);

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
                // The DENOMINATOR behind `progress`, and the only thing that
                // can tell "0% done" apart from "nothing to do yet" (ADR 0042).
                // `open_work_count` cannot: a project whose work is all
                // finished has none open and is 100%.
                //
                // The same filter the roll-up command uses — cancelled work is
                // not counted, by either of them — because a percentage and
                // the count that explains it must be computed over the same
                // set or the explanation contradicts the number.
                'countable_work_count' => DB::table('work_items')
                    ->selectRaw('count(*)')
                    ->whereColumn('work_items.project_id', 'projects.id')
                    ->whereNull('work_items.deleted_at')
                    ->where('work_items.state_category', '<>', 'cancelled'),
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
     * How many cards a column hands over.
     *
     * A window, not a page: there is no "next" — a board is read from the top
     * of each column, and someone who needs the hundredth card in Backlog is
     * looking for a list, not a board (the project's item list is that list).
     */
    private const CARDS_PER_COLUMN = 50;

    /**
     * Board data: one query for the columns, one for the counts, one for the cards.
     *
     * Deliberately NOT one query per column — a seven-column board would then
     * cost seven round trips before a single card renders.
     *
     * And deliberately not every card, either. This method used to hydrate
     * every work item in the project into Eloquent models; on the demo seed
     * that is a few hundred and looks fine, and against the volume fixture it
     * exhausts PHP's 128 MB and the board answers 500. Nothing about the code
     * said "this scales with the project" — the bug was the absence of a bound,
     * which is invisible until a project gets busy.
     *
     * So the column reports its TRUE total and hands over the first
     * CARDS_PER_COLUMN cards. The count is a fact about the column; the cards
     * are a list for the reader, and the two are allowed to differ as long as
     * the difference is stated (the same rule the Insights lists follow).
     */
    public function board(string $key): ApiResponse
    {
        $project = $this->visibleProjects()->where('key', mb_strtoupper($key))->firstOrFail();

        $this->authorize('view', $project);

        $states = DB::table('workflow_states')
            ->where('workflow_id', $project->workflow_id)
            ->orderBy('position')
            ->get(['id', 'key', 'label', 'category', 'color', 'position']);

        $totals = DB::table('work_items')
            ->selectRaw('workflow_state_id, count(*) as total')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->groupBy('workflow_state_id')
            ->get()
            ->keyBy('workflow_state_id');

        // The top of each column in one pass. A window function ranks within
        // the state rather than the query being run once per column, which is
        // the round-trip cost this method was written to avoid in the first
        // place.
        /** @var list<string> $visible */
        $visible = DB::query()
            ->fromSub(
                DB::table('work_items')
                    ->selectRaw('id, row_number() over (partition by workflow_state_id order by position) as card_rank')
                    ->where('organization_id', $this->tenant->organizationId())
                    ->where('project_id', $project->id)
                    ->whereNull('deleted_at'),
                'ranked',
            )
            ->where('card_rank', '<=', self::CARDS_PER_COLUMN)
            ->pluck('id')
            ->all();

        $items = WorkItemModel::query()
            ->with(['state', 'assignments.membership.user:id,name,avatar_path'])
            ->withCount('children')
            ->whereIn('id', $visible)
            ->orderBy('position')
            ->get();

        return $this->ok([
            'project' => new ProjectResource($project),
            'columns' => $states->map(function ($state) use ($items, $totals): array {
                $cards = $items->where('workflow_state_id', $state->id)->values();
                $total = (int) ($totals[$state->id]->total ?? 0);

                return [
                    'state' => $state,
                    'total' => $total,
                    // Stated rather than left to be inferred from a length: a
                    // reader who has to subtract two numbers to learn that the
                    // column is truncated will not subtract them.
                    'hidden_count' => max(0, $total - $cards->count()),
                    'items' => WorkItemResource::collection($cards),
                ];
            })->values(),
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

    /**
     * Correct a project (ADR 0040).
     *
     * A project could be created and never corrected: this route did not exist
     * and this method was not written, while `project.update` was granted and
     * `ProjectPolicy::update` answered it. A typo in a project's name was
     * permanent.
     *
     * `key` is refused BY NAME rather than ignored. It is in every work item
     * reference the project has ever produced, so changing it is a migration
     * and not an edit — and a field silently dropped from a PATCH is a form
     * that appears to work until it reloads.
     */
    public function update(Request $request, string $key): ApiResponse
    {
        $project = $this->visibleProjects()->where('key', mb_strtoupper($key))->firstOrFail();

        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'string', 'max:20000'],
            'department_id' => ['sometimes', 'nullable', 'uuid'],
            'visibility' => ['sometimes', 'in:internal,private'],
            'priority' => ['sometimes', 'in:low,medium,high,urgent'],
            'status' => ['sometimes', Rule::in(ProjectModel::STATUSES)],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
            'key' => ['prohibited'],
        ], [
            'key.prohibited' => "A project's key cannot change: it is part of every work item reference it has produced.",
        ]);

        // `unset`, not `collect()->except()`: `validate()` answers a plain
        // array and PHPStan cannot resolve Collection's key and value template
        // types through it. A helper that costs two generic errors to save one
        // line is not saving anything.
        $changes = $validated;
        unset($changes['lock_version'], $changes['key']);

        $updated = $this->projects->update(
            $project,
            $changes,
            // has(), not `?: null`: version 0 is a real version — the one every
            // freshly created project has — and folding it into "no version
            // sent" disables optimistic locking for the first edit of every
            // project (docs/03 §8).
            $request->has('lock_version') ? $request->integer('lock_version') : null,
        );

        return $this->ok(new ProjectResource($updated->load(['owner.user:id,name', 'department:id,name'])));
    }

    /**
     * Archive or restore. Not a DELETE, and not a status.
     *
     * A control is named for what it DOES: this takes a project off the boards
     * and leaves every work item, hour and history entry where they are.
     * Calling it "delete" would promise an erasure this deliberately refuses.
     */
    public function archive(Request $request, string $key): ApiResponse
    {
        $project = $this->visibleProjects()->where('key', mb_strtoupper($key))->firstOrFail();

        $this->authorize('archive', $project);

        $validated = $request->validate(['archived' => ['required', 'boolean']]);

        $updated = $this->projects->setArchived($project, (bool) $validated['archived']);

        return $this->ok(new ProjectResource($updated->load(['owner.user:id,name', 'department:id,name'])));
    }

    /**
     * Who is on this project (ADR 0041).
     *
     * `project_members` has decided project visibility since Phase 2 and had
     * no write path at all: the creator was inserted as the owner and nobody
     * could ever be added. A project created as PRIVATE was therefore visible
     * to exactly one person, for ever — the create form offered a setting the
     * product could not complete.
     */
    public function members(string $key): ApiResponse
    {
        $project = $this->visibleProjects()->where('key', mb_strtoupper($key))->firstOrFail();

        $this->authorize('view', $project);

        return ApiResponse::collection(
            $this->projects->members($project)->map($this->presentMember(...))->all(),
        );
    }

    public function addMember(Request $request, string $key): ApiResponse
    {
        $project = $this->visibleProjects()->where('key', mb_strtoupper($key))->firstOrFail();

        $this->authorize('manageMembers', $project);

        $validated = $request->validate([
            'membership_id' => ['sometimes', 'nullable', 'uuid'],
            'team_id' => ['sometimes', 'nullable', 'uuid'],
            'role' => ['sometimes', Rule::in(['owner', 'manager', 'member', 'viewer'])],
        ]);

        $member = $this->projects->addMember(
            $project,
            $validated['membership_id'] ?? null,
            $validated['team_id'] ?? null,
            $validated['role'] ?? 'member',
        );

        return ApiResponse::item($this->presentMember($member->fresh([
            'membership.user:id,name,avatar_path', 'team:id,name,key',
        ]) ?? $member), status: 201);
    }

    public function setMemberRole(Request $request, string $key, string $member): ApiResponse
    {
        $project = $this->visibleProjects()->where('key', mb_strtoupper($key))->firstOrFail();

        $this->authorize('manageMembers', $project);

        $validated = $request->validate([
            'role' => ['required', Rule::in(['owner', 'manager', 'member', 'viewer'])],
        ]);

        $updated = $this->projects->setMemberRole($project, $member, $validated['role']);

        return $this->ok($this->presentMember($updated->fresh([
            'membership.user:id,name,avatar_path', 'team:id,name,key',
        ]) ?? $updated));
    }

    public function removeMember(string $key, string $member): ApiResponse
    {
        $project = $this->visibleProjects()->where('key', mb_strtoupper($key))->firstOrFail();

        $this->authorize('manageMembers', $project);

        $this->projects->removeMember($project, $member);

        return $this->noContent();
    }

    /**
     * One row of the member list.
     *
     * A person and a team are presented in the same shape with a `subject`
     * that says which. The alternative — two arrays, or a nullable person and
     * a nullable team — makes every consumer branch before it can render a
     * name, and this list is rendered in a table where they are one column.
     *
     * @return array<string, mixed>
     */
    private function presentMember(ProjectMemberModel $member): array
    {
        return [
            'id' => $member->id,
            'subject' => $member->team_id === null ? 'person' : 'team',
            'membership_id' => $member->membership_id,
            'team_id' => $member->team_id,
            'name' => $member->team_id === null
                ? $member->membership?->user?->name
                : $member->team?->name,
            'avatar_url' => $member->membership?->user?->avatar_path,
            'role' => $member->role,
            'added_at' => $member->added_at->toIso8601String(),
        ];
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

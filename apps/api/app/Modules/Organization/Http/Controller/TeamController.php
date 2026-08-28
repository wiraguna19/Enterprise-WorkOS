<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controller;

use App\Modules\Organization\Application\Service\TeamService;
use App\Modules\Organization\Http\Resource\TeamResource;
use App\Modules\Organization\Infrastructure\Eloquent\TeamModel;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;

final class TeamController extends ApiController
{
    /**
     * Everything TeamResource reads for a single team.
     *
     * Named once and shared, because the resource is the thing that decides
     * what must be loaded and it is not the caller's job to remember. Getting
     * this list wrong does not degrade — lazy loading is disabled outside
     * production, so a missing relation is a 500 on a write that otherwise
     * succeeded, which is exactly how `addMember` shipped.
     *
     * @var list<string>
     */
    private const DETAIL_RELATIONS = [
        'department',
        'members.membership.user',
        'members.membership.employeeProfile',
    ];

    public function __construct(
        private readonly TeamService $teams,
    ) {}

    public function index(Request $request): ApiResponse
    {
        $teams = TeamModel::query()
            ->with('department')
            ->withCount('members')
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->active())
            ->when($request->filled('filter.department_id'), fn ($q) => $q
                ->where('department_id', $request->input('filter.department_id')))
            ->orderBy('name')
            ->get();

        return $this->ok(TeamResource::collection($teams));
    }

    public function show(TeamModel $team): ApiResponse
    {
        $this->authorize('view', $team);

        return $this->ok(new TeamResource($team->load(self::DETAIL_RELATIONS)));
    }

    public function store(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'key' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'department_id' => ['nullable', 'uuid'],
            'lead_membership_id' => ['nullable', 'uuid'],
        ]);

        $team = $this->teams->create(
            name: $validated['name'],
            key: $validated['key'],
            departmentId: $validated['department_id'] ?? null,
            leadMembershipId: $validated['lead_membership_id'] ?? null,
        );

        return $this->created(new TeamResource($team));
    }

    public function addMember(Request $request, TeamModel $team): ApiResponse
    {
        $this->authorize('manageMembers', $team);

        $validated = $request->validate([
            'membership_id' => ['required', 'uuid'],
            'role' => ['sometimes', 'in:member,lead'],
        ]);

        $this->teams->addMember($team, $validated['membership_id'], $validated['role'] ?? 'member');

        return $this->ok(new TeamResource($team->refresh()->load(self::DETAIL_RELATIONS)));
    }

    public function removeMember(TeamModel $team, string $membership): ApiResponse
    {
        $this->authorize('manageMembers', $team);

        $this->teams->removeMember($team, $membership);

        return $this->noContent();
    }
}

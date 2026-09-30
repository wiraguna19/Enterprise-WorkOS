<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Service\Milestones;
use App\Modules\Work\Infrastructure\Eloquent\MilestoneModel;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A project's milestones (docs/05 §1 `/projects/{id}/milestones`, ADR 0056).
 *
 * Nested under the project's KEY, like its members: a milestone is only ever
 * reached from its project, and the project's visibility is the milestone's
 * (docs/06 §2) — a milestone of a project you cannot see is a 404, whatever
 * its id.
 */
final class MilestoneController extends ApiController
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
        private readonly Milestones $milestones,
    ) {}

    public function index(string $key): ApiResponse
    {
        $project = $this->project($key);

        $this->authorize('view', $project);

        return $this->ok($this->milestones->of($project)->map($this->present(...))->all());
    }

    public function store(Request $request, string $key): ApiResponse
    {
        $project = $this->project($key);

        $this->authorize('manageMilestones', $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $milestone = $this->milestones->create(
            $project,
            (string) $validated['name'],
            (string) ($validated['description'] ?? ''),
            isset($validated['due_date']) ? (string) $validated['due_date'] : null,
        );

        return $this->created($this->present($milestone));
    }

    public function update(Request $request, string $key, string $id): ApiResponse
    {
        $project = $this->project($key);

        $this->authorize('manageMilestones', $project);

        $milestone = $this->milestoneOf($project, $id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(Milestones::STATUSES)],
        ]);

        // A null description is "no description", which the column spells ''.
        if (array_key_exists('description', $validated)) {
            $validated['description'] = (string) ($validated['description'] ?? '');
        }

        /** @var array{name?: string, description?: string, due_date?: string|null, status?: string} $validated */
        $updated = $this->milestones->update($milestone, $validated);

        return $this->ok($this->present($updated));
    }

    public function destroy(string $key, string $id): ApiResponse
    {
        $project = $this->project($key);

        $this->authorize('manageMilestones', $project);

        $this->milestones->delete($this->milestoneOf($project, $id));

        return $this->noContent();
    }

    /** @return array<string, mixed> */
    private function present(MilestoneModel $milestone): array
    {
        return [
            'id' => $milestone->id,
            'name' => $milestone->name,
            'description' => $milestone->description,
            'due_date' => $milestone->due_date?->toDateString(),
            'status' => $milestone->status,
            'completed_at' => $milestone->completed_at?->toIso8601String(),
            'work_count' => $milestone->work_count ?? null,
            'open_work_count' => $milestone->open_work_count ?? null,
        ];
    }

    private function project(string $key): ProjectModel
    {
        /** @var MembershipModel $actor */
        $actor = $this->acting->getOrFail();

        return ProjectModel::query()
            ->visibleTo($this->tenant->membershipId(), $this->permissions->has($actor, 'project.view_all'))
            ->where('key', mb_strtoupper($key))
            ->firstOrFail();
    }

    private function milestoneOf(ProjectModel $project, string $id): MilestoneModel
    {
        // A malformed id is simply not found: comparing a uuid column with
        // "banana" is a Postgres error, which would be a 500 for a typo.
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        return MilestoneModel::query()
            ->where('project_id', $project->getKey())
            ->whereKey($id)
            ->firstOrFail();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Service\WorkItemTemplates;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemTemplateModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Work item templates (ADR 0047).
 *
 * One read and three writes, and the read is guarded differently from the
 * writes on purpose: the person who reads a template is the person filling in
 * the create form, so it sits behind `work_item.create`. Custom fields needed
 * two list endpoints because the administrator's list holds retired fields and
 * answer counts the form may not see; a template holds nothing the form may
 * not see, so one list serves both screens and cannot drift from itself.
 */
final class WorkItemTemplateController extends ApiController
{
    public function __construct(
        private readonly WorkItemTemplates $templates,
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
    ) {}

    public function index(): ApiResponse
    {
        /** @var list<string> $visible */
        $visible = $this->visibleProjects()->pluck('id')->map(strval(...))->all();

        return ApiResponse::collection(
            $this->templates->all($visible)->map($this->present(...))->all(),
        );
    }

    public function store(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:500'],
            // A project KEY, as everywhere a person names one. Absent: an
            // organization-wide template. Fixed once made — moving a template
            // between projects is deleting one and writing another.
            'project' => ['sometimes', 'nullable', 'string', 'max:12'],
            ...$this->fieldRules(required: true),
        ]);

        $project = $request->filled('project')
            ? $this->visibleProjects()->where('key', mb_strtoupper($request->string('project')->toString()))->firstOrFail()
            : null;

        $this->authorizeWrite($project);

        /** @var array<string, mixed> $fields */
        $fields = $request->input('fields', []);

        $template = $this->templates->create(
            name: $validated['name'],
            purpose: $validated['purpose'] ?? null,
            fields: $fields,
            projectId: $project?->id,
        );

        return $this->created($this->present($template));
    }

    public function update(Request $request, string $id): ApiResponse
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:500'],
            ...$this->fieldRules(required: false),
        ]);

        // Read from the INPUT, not from `validated()`. The validated array holds
        // only keys that have a rule, so a refused key like `assignee_id` would
        // never reach the service that names it — it would be dropped, and the
        // save would look like it worked.
        /** @var array{name?: string, purpose?: string|null, fields?: array<string, mixed>} $changes */
        $changes = $request->only(['name', 'purpose', 'fields']);

        $template = $this->templates->find($id);
        $this->authorizeWrite($template->project_id === null ? null : $this->projectOf($template));

        $template = $this->templates->update($template, $changes);

        return $this->ok($this->present($template));
    }

    public function destroy(string $id): ApiResponse
    {
        $template = $this->templates->find($id);
        $this->authorizeWrite($template->project_id === null ? null : $this->projectOf($template));

        $this->templates->delete($template);

        return $this->noContent();
    }

    /**
     * The values of the built-in fields; the KEYS are the service's business.
     *
     * Types and priorities come from the model's own constants rather than a
     * list written here — two lists that must agree eventually will not.
     * Custom field answers are checked against their definitions by the
     * service, which is the only thing that can see them.
     *
     * @return array<string, list<mixed>>
     */
    private function fieldRules(bool $required): array
    {
        return [
            'fields' => [$required ? 'required' : 'sometimes', 'array'],
            'fields.title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'fields.description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'fields.type' => ['sometimes', 'nullable', Rule::in(WorkItemModel::TYPES)],
            'fields.priority' => ['sometimes', 'nullable', Rule::in(WorkItemModel::PRIORITIES)],
            'fields.estimate_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
            // The recurrence template's bound, for the recurrence template's
            // reason: a year ahead is a plan, not a template.
            'fields.due_in_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'fields.custom_fields' => ['sometimes', 'nullable', 'array'],
            'fields.custom_fields.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(WorkItemTemplateModel $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'purpose' => $template->purpose,
            // An object even when empty, never `[]`: PHP encodes an empty array
            // as a JSON list, and a client reading `fields.title` off a list
            // gets undefined for a reason nobody would guess.
            'fields' => (object) $template->fields,
            'project' => $template->project === null ? null : [
                'id' => $template->project->id,
                'key' => $template->project->key,
                'name' => $template->project->name,
            ],
            'updated_at' => $template->updated_at->toIso8601String(),
        ];
    }

    /**
     * Who may write a template (ADR 0058).
     *
     * An organization-wide one: `work_item_template.manage`, as before. A
     * project's: that, OR whoever may change the project — its owner and
     * managers, through ProjectPolicy::update — because a project's starting
     * points are part of how the project is run, and asking an administrator
     * for each one is the round trip ADR 0047 left owed.
     *
     * The route is guarded only on `project.view`, and this decides: a route
     * that demanded the template permission would refuse a project manager
     * before the question could be asked.
     */
    private function authorizeWrite(?ProjectModel $project): void
    {
        /** @var MembershipModel $actor */
        $actor = $this->acting->getOrFail();

        if ($this->permissions->has($actor, 'work_item_template.manage')) {
            return;
        }

        if ($project !== null) {
            $this->authorize('update', $project);

            return;
        }

        abort(403, 'Organization-wide templates are written by people with work_item_template.manage.');
    }

    /** A template's project, if the reader can see it — else 404, like the template itself. */
    private function projectOf(WorkItemTemplateModel $template): ProjectModel
    {
        return $this->visibleProjects()->whereKey($template->project_id)->firstOrFail();
    }

    /** @return Builder<ProjectModel> */
    private function visibleProjects(): Builder
    {
        /** @var MembershipModel $actor */
        $actor = $this->acting->getOrFail();

        return ProjectModel::query()->visibleTo(
            $this->tenant->membershipId(),
            $this->permissions->has($actor, 'project.view_all'),
        );
    }
}

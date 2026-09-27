<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Service\WorkItemTemplates;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemTemplateModel;
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
    ) {}

    public function index(): ApiResponse
    {
        return ApiResponse::collection(
            $this->templates->all()->map($this->present(...))->all(),
        );
    }

    public function store(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:500'],
            ...$this->fieldRules(required: true),
        ]);

        /** @var array<string, mixed> $fields */
        $fields = $request->input('fields', []);

        $template = $this->templates->create(
            name: $validated['name'],
            purpose: $validated['purpose'] ?? null,
            fields: $fields,
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

        $template = $this->templates->update($this->templates->find($id), $changes);

        return $this->ok($this->present($template));
    }

    public function destroy(string $id): ApiResponse
    {
        $this->templates->delete($this->templates->find($id));

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
            'updated_at' => $template->updated_at->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Governance\Http\Controller;

use App\Modules\Governance\Application\Service\CustomFields;
use App\Modules\Governance\Domain\Exception\CustomFieldRefused;
use App\Modules\Governance\Infrastructure\Eloquent\CustomFieldDefinitionModel;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administering the fields an organization declared for itself (ADR 0038).
 *
 * The whole definition side lives here. Answers are written through the record
 * they belong to — a work item's custom fields are saved by the work item
 * endpoint, in the same transaction and against the same permission — because
 * "edit this item, except these parts" is a state nothing in this product can
 * show and nobody asked for (see the permission migration).
 *
 * `scope` is a path segment rather than a query parameter. A field belongs to
 * exactly one kind of record, the screens are separate, and a collection whose
 * contents change shape with a query string is a collection nothing can cache
 * or link to.
 */
final class CustomFieldController extends ApiController
{
    public function __construct(
        private readonly CustomFields $fields,
    ) {}

    /**
     * The vocabulary, served rather than duplicated.
     *
     * Types and scopes are decided by a CHECK constraint and a value column;
     * every copy of that list on a client is a list that goes stale silently.
     * This product has paid for that four times over (`SUPPORTED_FORMATS`, the
     * report requirements, `WorkItemModel::TYPES`), and the rule it settled on
     * is that the thing which consumes a value is the thing that names it.
     */
    public function vocabulary(): ApiResponse
    {
        return ApiResponse::item([
            'scopes' => CustomFieldDefinitionModel::SCOPES,
            'types' => array_keys(CustomFieldDefinitionModel::VALUE_COLUMNS),
        ]);
    }

    public function index(string $scope): ApiResponse
    {
        $this->assertScope($scope);

        return ApiResponse::collection(
            $this->fields->all($scope)->map($this->present(...))->all(),
        );
    }

    public function store(Request $request, string $scope): ApiResponse
    {
        $this->assertScope($scope);

        $validated = $request->validate([
            // The shape is checked here AND by a CHECK constraint. Not
            // redundant: the constraint is what makes it true of every row
            // whatever writes it, and this is what makes the refusal a sentence
            // the person can act on instead of a 500.
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
            'label' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(CustomFieldDefinitionModel::VALUE_COLUMNS))],
            'required' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'array'],
            'config.options' => ['sometimes', 'array', 'max:100'],
            'config.options.*' => ['string', 'max:80'],
        ]);

        /** @var array<string, mixed> $config */
        $config = $validated['config'] ?? [];

        $definition = $this->fields->declare(
            scope: $scope,
            key: $validated['key'],
            label: $validated['label'],
            type: $validated['type'],
            config: $config,
            required: (bool) ($validated['required'] ?? false),
        );

        return ApiResponse::item($this->present($definition), status: 201);
    }

    public function update(Request $request, string $scope, string $id): ApiResponse
    {
        $this->assertScope($scope);

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:80'],
            'required' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'array'],
            'config.options' => ['sometimes', 'array', 'max:100'],
            'config.options.*' => ['string', 'max:80'],
            // Accepted only so the service can refuse them BY NAME. Leaving
            // them out of the rules would drop them silently, and the screen
            // would show a renamed key until it reloaded.
            'key' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string'],
        ]);

        /** @var array{label?: string, config?: array<string, mixed>, required?: bool, key?: string, type?: string} $changes */
        $changes = $validated;

        $definition = $this->fields->update($this->findInScope($scope, $id), $changes);

        return ApiResponse::item($this->present($definition));
    }

    /** Retire or bring back — the two directions of the same switch. */
    public function setLive(Request $request, string $scope, string $id): ApiResponse
    {
        $this->assertScope($scope);

        $validated = $request->validate([
            'live' => ['required', 'boolean'],
        ]);

        $definition = $this->findInScope($scope, $id);

        $definition = $validated['live']
            ? $this->fields->restore($definition)
            : $this->fields->retire($definition);

        return ApiResponse::item($this->present($definition));
    }

    public function destroy(string $scope, string $id): ApiResponse
    {
        $this->assertScope($scope);

        $this->fields->delete($this->findInScope($scope, $id));

        return $this->noContent();
    }

    public function reorder(Request $request, string $scope): ApiResponse
    {
        $this->assertScope($scope);

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['uuid'],
        ]);

        /** @var list<string> $order */
        $order = array_values($validated['order']);

        $this->fields->reorder($scope, $order);

        return ApiResponse::collection(
            $this->fields->all($scope)->map($this->present(...))->all(),
        );
    }

    /**
     * A field of another scope is not found here, not merely refused.
     *
     * `/custom-fields/project/{id}` where the id names a work-item field is a
     * request for something that does not exist at that address; answering 200
     * would let one screen edit the other's fields by accident.
     */
    private function findInScope(string $scope, string $id): CustomFieldDefinitionModel
    {
        $definition = $this->fields->find($id);

        if ($definition->scope !== $scope) {
            throw CustomFieldRefused::unknownField($id);
        }

        return $definition;
    }

    private function assertScope(string $scope): void
    {
        if (! in_array($scope, CustomFieldDefinitionModel::SCOPES, strict: true)) {
            throw CustomFieldRefused::unknownField($scope);
        }
    }

    /** @return array<string, mixed> */
    private function present(CustomFieldDefinitionModel $definition): array
    {
        return [
            'id' => $definition->id,
            'scope' => $definition->scope,
            'key' => $definition->key,
            // What a filter would name it. Served rather than assembled on the
            // client, so the prefix is decided in one place (docs/05 §4).
            'filter_key' => 'cf_'.$definition->key,
            'label' => $definition->label,
            'type' => $definition->type,
            'options' => $definition->options(),
            'required' => $definition->required,
            'position' => $definition->position,
            'live' => $definition->isLive(),
            'retired_at' => $definition->archived_at?->toIso8601String(),
        ];
    }
}

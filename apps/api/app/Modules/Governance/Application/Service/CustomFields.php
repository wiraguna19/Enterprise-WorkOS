<?php

declare(strict_types=1);

namespace App\Modules\Governance\Application\Service;

use App\Modules\Governance\Domain\Exception\CustomFieldRefused;
use App\Modules\Governance\Infrastructure\Eloquent\CustomFieldDefinitionModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Declaring, editing and retiring an organization's own fields (ADR 0038).
 *
 * The definition side only. Answers live in {@see CustomFieldValues}, and the
 * split is the one docs/02 §9 draws: what may be asked is an organization
 * setting, what was answered is part of a record.
 */
final class CustomFields
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Every field declared for a scope, retired ones included.
     *
     * The administration screen needs the retired ones — they are still on
     * records, and the only place anybody can see that they exist is here.
     * Callers that want the form's list ask {@see live()}.
     *
     * @return Collection<int, CustomFieldDefinitionModel>
     */
    public function all(string $scope): Collection
    {
        return CustomFieldDefinitionModel::query()
            ->where('scope', $scope)
            // Retired last, then by the order the administrator chose. A
            // retired field sorted among the live ones reads as live.
            ->orderByRaw('archived_at IS NOT NULL')
            ->orderBy('position')
            ->orderBy('label')
            ->get();
    }

    /**
     * The fields a form should show, in order.
     *
     * @return Collection<int, CustomFieldDefinitionModel>
     */
    public function live(string $scope): Collection
    {
        return CustomFieldDefinitionModel::query()
            ->where('scope', $scope)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('label')
            ->get();
    }

    /**
     * One definition by the key a filter names, or null.
     *
     * Retired fields included. A retired field still holds answers on the
     * records that carry them, and "show me the items that said Acme" is a
     * question about those records — refusing it would hide data that is still
     * there because the form stopped asking for more of it.
     */
    public function byKey(string $scope, string $key): ?CustomFieldDefinitionModel
    {
        $definition = CustomFieldDefinitionModel::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->first();

        return $definition instanceof CustomFieldDefinitionModel ? $definition : null;
    }

    public function find(string $id): CustomFieldDefinitionModel
    {
        $definition = CustomFieldDefinitionModel::query()->find($id);

        if (! $definition instanceof CustomFieldDefinitionModel) {
            throw CustomFieldRefused::unknownField($id);
        }

        return $definition;
    }

    /**
     * Declare a field.
     *
     * The uniqueness of the key is checked by the database, not by a SELECT
     * first: two administrators declaring `client` in the same second would
     * both pass a read and one would fail the write anyway, so the unique index
     * is the only place the answer is actually decided. The catch turns that
     * into the sentence the screen can show.
     *
     * @param  array<string, mixed>  $config
     */
    public function declare(
        string $scope,
        string $key,
        string $label,
        string $type,
        array $config = [],
        bool $required = false,
    ): CustomFieldDefinitionModel {
        $config = $this->validatedConfig($type, $config);

        $definition = new CustomFieldDefinitionModel;
        $definition->id = (string) new UuidV7;
        $definition->scope = $scope;
        $definition->key = $key;
        $definition->label = $label;
        $definition->type = $type;
        $definition->config = $config;
        $definition->required = $required;
        $definition->position = $this->nextPosition($scope);

        try {
            $definition->save();
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                throw CustomFieldRefused::keyTaken($key);
            }

            throw $e;
        }

        $this->audit->record('custom_field.declared', [
            'scope' => $scope,
            'key' => $key,
            'type' => $type,
            'required' => $required,
        ], targetType: 'custom_field_definition', targetId: $definition->id);

        return $definition;
    }

    /**
     * Change what may change.
     *
     * The key and the type are refused by name rather than ignored. Silently
     * dropping a field from an update is how a client ships a control that
     * appears to work — the screen shows the new value until it reloads.
     *
     * @param  array{label?: string, config?: array<string, mixed>, required?: bool, key?: string, type?: string}  $changes
     */
    public function update(CustomFieldDefinitionModel $definition, array $changes): CustomFieldDefinitionModel
    {
        if (array_key_exists('key', $changes) && $changes['key'] !== $definition->key) {
            throw CustomFieldRefused::keyIsFrozen();
        }

        if (array_key_exists('type', $changes) && $changes['type'] !== $definition->type) {
            throw CustomFieldRefused::typeIsFrozen();
        }

        $before = [
            'label' => $definition->label,
            'required' => $definition->required,
            'options' => $definition->options(),
        ];

        if (array_key_exists('label', $changes)) {
            $definition->label = $changes['label'];
        }

        if (array_key_exists('config', $changes)) {
            $definition->config = $this->validatedConfig($definition->type, $changes['config']);
        }

        if (array_key_exists('required', $changes)) {
            $definition->required = $changes['required'];
        }

        $definition->save();

        $this->audit->record('custom_field.updated', [
            'key' => $definition->key,
            'before' => $before,
            'after' => [
                'label' => $definition->label,
                'required' => $definition->required,
                'options' => $definition->options(),
            ],
        ], targetType: 'custom_field_definition', targetId: $definition->id);

        return $definition;
    }

    /**
     * Retire a field: stop collecting it, keep what was collected.
     *
     * The answers stay on the records that carry them, and the record screen
     * keeps showing them. A field that vanished from the items it was on would
     * rewrite history to make a form tidier.
     */
    public function retire(CustomFieldDefinitionModel $definition): CustomFieldDefinitionModel
    {
        if ($definition->isLive()) {
            $definition->archived_at = now()->toImmutable();
            $definition->save();

            $this->audit->record('custom_field.retired', [
                'key' => $definition->key,
                'answers' => $this->answerCount($definition),
            ], targetType: 'custom_field_definition', targetId: $definition->id);
        }

        return $definition;
    }

    public function restore(CustomFieldDefinitionModel $definition): CustomFieldDefinitionModel
    {
        if (! $definition->isLive()) {
            $definition->archived_at = null;
            $definition->save();

            $this->audit->record('custom_field.restored', [
                'key' => $definition->key,
            ], targetType: 'custom_field_definition', targetId: $definition->id);
        }

        return $definition;
    }

    /**
     * Delete a field and everything answered into it.
     *
     * The one honest case is "declared by mistake, never used", so the count of
     * answers it will take with it is recorded in the audit entry BEFORE the
     * cascade removes the evidence. An administrator reading the log a month
     * later needs to know whether this deletion cost anything.
     */
    public function delete(CustomFieldDefinitionModel $definition): void
    {
        $answers = $this->answerCount($definition);
        $key = $definition->key;
        $id = $definition->id;

        $definition->delete();

        $this->audit->record('custom_field.deleted', [
            'key' => $key,
            'answers_destroyed' => $answers,
        ], targetType: 'custom_field_definition', targetId: $id);
    }

    /**
     * Put the fields of one scope in the given order.
     *
     * Positions are rewritten from the list rather than swapped pairwise: a
     * partial reorder leaves two fields sharing a position, and the tiebreak
     * then decides the form's layout, which is how a drag lands somewhere the
     * person did not drop it.
     *
     * @param  list<string>  $orderedIds
     */
    public function reorder(string $scope, array $orderedIds): void
    {
        DB::transaction(function () use ($scope, $orderedIds): void {
            foreach ($orderedIds as $position => $id) {
                CustomFieldDefinitionModel::query()
                    ->where('scope', $scope)
                    ->where('id', $id)
                    ->update(['position' => $position, 'updated_at' => now()]);
            }
        });

        $this->audit->record('custom_field.reordered', [
            'scope' => $scope,
            'order' => $orderedIds,
        ]);
    }

    /**
     * Per-type settings, validated here because the shape differs per type.
     *
     * Unknown keys are dropped rather than stored. `config` is a jsonb column
     * with no constraint on it, so anything written there is read back
     * forever — and a setting nothing honours is indistinguishable from one
     * that stopped working.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function validatedConfig(string $type, array $config): array
    {
        if ($type !== 'select') {
            return [];
        }

        $options = $config['options'] ?? [];

        if (! is_array($options)) {
            throw CustomFieldRefused::selectNeedsOptions();
        }

        $options = array_values(array_filter(
            array_map(static fn (mixed $option): string => trim((string) $option), $options),
            static fn (string $option): bool => $option !== '',
        ));

        // Duplicates removed rather than refused: two identical options are a
        // paste accident, and the person cannot tell them apart in the picker
        // anyway.
        $options = array_values(array_unique($options));

        if ($options === []) {
            throw CustomFieldRefused::selectNeedsOptions();
        }

        return ['options' => $options];
    }

    private function nextPosition(string $scope): int
    {
        $highest = CustomFieldDefinitionModel::query()
            ->where('scope', $scope)
            ->max('position');

        return is_numeric($highest) ? ((int) $highest) + 1 : 0;
    }

    private function answerCount(CustomFieldDefinitionModel $definition): int
    {
        return DB::table('custom_field_values')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('definition_id', $definition->id)
            ->count();
    }

    /**
     * Postgres' unique-violation SQLSTATE.
     *
     * Matched on the code, not on the message: the message names the index and
     * is localised by the server's lc_messages, so a check against its text
     * works on the developer's machine and stops working in production.
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        return $e->getCode() === '23505';
    }
}

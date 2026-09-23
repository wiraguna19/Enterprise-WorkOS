<?php

declare(strict_types=1);

namespace App\Modules\Governance\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;

/**
 * A field an organization declared for itself (docs/02 §9, ADR 0038).
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $scope
 * @property string $key
 * @property string $label
 * @property string $type
 * @property array<string, mixed> $config
 * @property bool $required
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CustomFieldDefinitionModel extends TenantModel
{
    protected $table = 'custom_field_definitions';

    /**
     * What a field can hang off.
     *
     * The list is here rather than in a validator because
     * `custom_field_values` has one column per entry: a scope with no column
     * behind it is a field that can be declared and never answered, which is
     * the dead control this product keeps finding.
     *
     * @var list<string>
     */
    public const SCOPES = ['work_item', 'project'];

    /**
     * The types that exist, and the column each one answers into.
     *
     * One map rather than a list plus a switch somewhere else: the thing that
     * consumes a value is the thing that should name it, and two lists that
     * must agree eventually will not.
     *
     * @var array<string, string>
     */
    public const VALUE_COLUMNS = [
        'text' => 'value_text',
        'number' => 'value_number',
        'date' => 'value_date',
        'select' => 'value_text',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'required' => 'boolean',
            'position' => 'integer',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Which typed column holds this field's answer. */
    public function valueColumn(): string
    {
        return self::VALUE_COLUMNS[$this->type];
    }

    /**
     * The options a `select` offers, in the order they were declared.
     *
     * Returns an empty list for every other type rather than null, so a caller
     * iterating options does not have to know which types have them.
     *
     * @return list<string>
     */
    public function options(): array
    {
        if ($this->type !== 'select') {
            return [];
        }

        $options = $this->config['options'] ?? [];

        if (! is_array($options)) {
            return [];
        }

        return array_values(array_map(strval(...), $options));
    }

    public function isLive(): bool
    {
        return $this->archived_at === null;
    }
}

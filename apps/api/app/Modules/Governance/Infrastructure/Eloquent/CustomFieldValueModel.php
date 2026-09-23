<?php

declare(strict_types=1);

namespace App\Modules\Governance\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;

/**
 * One answer to one declared field, on one subject (ADR 0038).
 *
 * The subject is a nullable `work_item_id` / `project_id` pair with exactly one
 * side filled, not a polymorphic `(type, id)`, so the database can CASCADE the
 * answers away with whatever they were written on. Governance may not import
 * Work or Organization (deptrac), so the columns are plain uuids here and the
 * relation is declared by the owning module's provider, as every cross-module
 * relation in this product is.
 *
 * Column types are hand-maintained; the schema is raw SQL (docs/03 §0).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $definition_id
 * @property string|null $work_item_id
 * @property string|null $project_id
 * @property string|null $value_text
 * @property string|null $value_number
 * @property CarbonImmutable|null $value_date
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CustomFieldValueModel extends TenantModel
{
    protected $table = 'custom_field_values';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // NOT a float. The column is numeric(18,4) because money and
            // quantities are why anybody adds a number field, and casting it
            // to a PHP float here would undo that in the one hop between the
            // database and the JSON the client reads. It travels as the
            // decimal string Postgres returns.
            'value_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

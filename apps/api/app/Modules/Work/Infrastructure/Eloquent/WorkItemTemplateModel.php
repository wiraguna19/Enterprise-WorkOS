<?php

declare(strict_types=1);

namespace App\Modules\Work\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;

/**
 * A named starting point for new work (ADR 0047).
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $purpose
 * @property array<string, mixed> $fields
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class WorkItemTemplateModel extends TenantModel
{
    protected $table = 'work_item_templates';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

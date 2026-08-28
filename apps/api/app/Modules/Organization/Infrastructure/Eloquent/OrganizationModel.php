<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant itself — deliberately NOT tenant-scoped (it is the scope).
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $plan
 * @property string $status
 * @property array<string, mixed> $settings
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class OrganizationModel extends BaseModel
{
    use SoftDeletes;

    protected $table = 'organizations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}

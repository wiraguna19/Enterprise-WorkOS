<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\BaseModel;
use Carbon\CarbonImmutable;

/**
 * Global catalogue, seeded by migration. Not per-tenant: `work_item.assign`
 * must mean the same thing in every organization or authorization is unauditable.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $key
 * @property string $resource
 * @property string $action
 * @property string $description
 * @property CarbonImmutable $created_at
 */
final class PermissionModel extends BaseModel
{
    protected $table = 'permissions';

    public $timestamps = false;
}

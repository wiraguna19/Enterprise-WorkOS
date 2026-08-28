<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property bool $is_system
 * @property int $level
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RoleModel extends TenantModel
{
    protected $table = 'roles';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'level' => 'integer'];
    }

    /** @return BelongsToMany<PermissionModel, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            PermissionModel::class,
            'role_permissions',
            'role_id',
            'permission_id',
        );
    }
}

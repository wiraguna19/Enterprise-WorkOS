<?php

declare(strict_types=1);

namespace App\Modules\Work\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $work_item_id
 * @property string $depends_on_work_item_id
 * @property string $type
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 */
final class WorkItemDependencyModel extends TenantModel
{
    protected $table = 'work_item_dependencies';

    public $timestamps = false;

    /** @return BelongsTo<WorkItemModel, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItemModel::class, 'work_item_id');
    }

    /** @return BelongsTo<WorkItemModel, $this> */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(WorkItemModel::class, 'depends_on_work_item_id');
    }
}

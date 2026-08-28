<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Eloquent;

use App\Modules\Platform\Domain\Work\StateCategory;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A status is a row, not an enum (docs/02 §7).
 *
 * `category` is the load-bearing column: the UI, reports, and overdue logic key
 * off it, never off `label`. That is what lets a customer rename "In Review" to
 * "QA Gate" and add three states of their own without breaking a dashboard.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $workflow_id
 * @property string $key
 * @property string $label
 * @property string $category
 * @property string $color
 * @property int $position
 * @property bool $is_initial
 * @property bool $is_terminal
 * @property bool $requires_approval
 * @property CarbonImmutable $created_at
 */
final class WorkflowStateModel extends TenantModel
{
    protected $table = 'workflow_states';

    public $timestamps = false;

    /**
     * The category vocabulary lives in Platform (StateCategory).
     *
     * Aliased here rather than moved-and-forgotten because a workflow state IS
     * the thing that carries a category, so this is where a reader looks for
     * it. The list itself belongs lower down: everything in the product asks
     * "is this finished", and nothing else needs to know a workflow engine
     * exists.
     */
    public const CATEGORIES = StateCategory::ALL;

    public const CLOSED_CATEGORIES = StateCategory::CLOSED;

    public const COMMITTED_CATEGORIES = StateCategory::COMMITTED;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_initial' => 'boolean',
            'is_terminal' => 'boolean',
            'requires_approval' => 'boolean',
        ];
    }

    /** @return BelongsTo<WorkflowModel, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowModel::class, 'workflow_id');
    }

    public function isClosed(): bool
    {
        return in_array($this->category, self::CLOSED_CATEGORIES, strict: true);
    }
}

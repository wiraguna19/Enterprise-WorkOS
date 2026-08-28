<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Eloquent;

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

    /** The seven fixed buckets every custom state must map to. */
    public const CATEGORIES = [
        'backlog', 'todo', 'in_progress', 'in_review', 'blocked', 'done', 'cancelled',
    ];

    /** Categories that mean "this work is finished, stop counting it". */
    public const CLOSED_CATEGORIES = ['done', 'cancelled'];

    /** Categories that consume capacity in the workload calculation (docs/02 §11). */
    public const COMMITTED_CATEGORIES = ['todo', 'in_progress', 'in_review'];

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

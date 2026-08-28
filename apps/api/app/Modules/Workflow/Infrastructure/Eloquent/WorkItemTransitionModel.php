<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * State history — what actually happened, as opposed to what is permitted.
 *
 * Lives in Workflow rather than Work even though every row is about a work
 * item, because it is the workflow's record of being walked. It references
 * `work_item_id` as a bare column and imports nothing from Work, so the module
 * boundary Deptrac enforces (Workflow may not depend on Work) holds.
 *
 * Append-only at the DATABASE level. There is no update path in this class and
 * there must never be one: cycle time, throughput, and every Phase 6 chart are
 * computed from these rows, and an UPDATE that "corrects" a timestamp rewrites
 * a number someone has already reported.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $work_item_id
 * @property string|null $from_state_id
 * @property string $to_state_id
 * @property string|null $from_category
 * @property string $to_category
 * @property string|null $actor_membership_id
 * @property string $cause
 * @property string|null $causation_id
 * @property int $causation_depth
 * @property string|null $override_reason
 * @property CarbonImmutable $occurred_at
 */
final class WorkItemTransitionModel extends TenantModel
{
    protected $table = 'work_item_transitions';

    public $timestamps = false;

    /** Why a transition happened. `rule` is the one users need explained. */
    public const CAUSES = ['user', 'rule', 'system', 'approval', 'import'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkflowStateModel, $this> */
    public function fromState(): BelongsTo
    {
        return $this->belongsTo(WorkflowStateModel::class, 'from_state_id');
    }

    /** @return BelongsTo<WorkflowStateModel, $this> */
    public function toState(): BelongsTo
    {
        return $this->belongsTo(WorkflowStateModel::class, 'to_state_id');
    }

    /** @param Builder<WorkItemTransitionModel> $query
     *  @return Builder<WorkItemTransitionModel> */
    public function scopeForWorkItem(Builder $query, string $workItemId): Builder
    {
        return $query->where('work_item_id', $workItemId)->orderBy('occurred_at');
    }

    /** Changes nobody made by hand — the ones that need explaining. */
    /** @param Builder<WorkItemTransitionModel> $query
     *  @return Builder<WorkItemTransitionModel> */
    public function scopeAutomated(Builder $query): Builder
    {
        return $query->whereIn('cause', ['rule', 'system', 'approval']);
    }

    public function wasAutomated(): bool
    {
        return in_array($this->cause, ['rule', 'system', 'approval'], strict: true);
    }
}

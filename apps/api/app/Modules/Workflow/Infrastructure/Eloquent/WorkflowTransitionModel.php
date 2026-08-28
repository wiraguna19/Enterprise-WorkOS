<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A legal move, as a row.
 *
 * `from_state_id` NULL means "from anywhere" — how Cancelled and Blocked become
 * reachable without enumerating every source state.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $workflow_id
 * @property string|null $from_state_id
 * @property string $to_state_id
 * @property string $label
 * @property array<string, mixed> $guard
 * @property bool $requires_comment
 * @property int $position
 * @property CarbonImmutable $created_at
 */
final class WorkflowTransitionModel extends TenantModel
{
    protected $table = 'workflow_transitions';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'guard' => 'array',
            'requires_comment' => 'boolean',
            'position' => 'integer',
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

    public function isWildcard(): bool
    {
        return $this->from_state_id === null;
    }
}

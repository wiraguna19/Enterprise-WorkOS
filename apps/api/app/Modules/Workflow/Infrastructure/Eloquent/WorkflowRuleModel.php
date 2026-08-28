<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trigger → condition → action, stored as data.
 *
 * `failure_count` and `disabled_reason` exist because the alternative — a rule
 * that fails silently forever — degrades every workflow it touches without
 * anyone noticing. A disabled rule is at least visible.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $workflow_id
 * @property string $name
 * @property string $description
 * @property string $trigger
 * @property array<string, mixed> $conditions
 * @property array<string, mixed> $actions
 * @property bool $is_active
 * @property int $run_order
 * @property int $failure_count
 * @property string|null $disabled_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class WorkflowRuleModel extends TenantModel
{
    protected $table = 'workflow_rules';

    public const TRIGGERS = [
        'work_item.status_changed',
        'work_item.assigned',
        'work_item.created',
        'approval.decided',
        'schedule.due_soon',
        'schedule.overdue',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions' => 'array',
            'is_active' => 'boolean',
            'run_order' => 'integer',
            'failure_count' => 'integer',
        ];
    }

    /** @param Builder<WorkflowRuleModel> $query
     *  @return Builder<WorkflowRuleModel> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isHealthy(): bool
    {
        return $this->is_active && $this->failure_count === 0;
    }
}

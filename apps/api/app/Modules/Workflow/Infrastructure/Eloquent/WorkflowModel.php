<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $applies_to_type
 * @property int $version
 * @property bool $is_default
 * @property bool $is_active
 * @property string|null $superseded_by_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class WorkflowModel extends TenantModel
{
    protected $table = 'workflows';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<WorkflowStateModel, $this> */
    public function states(): HasMany
    {
        return $this->hasMany(WorkflowStateModel::class, 'workflow_id')->orderBy('position');
    }

    /**
     * The edges of the graph.
     *
     * A workflow rendered as a list of states is a list, not a workflow: what
     * makes it a graph — and what an administrator needs to see before changing
     * anything — is which moves are legal.
     *
     * @return HasMany<WorkflowTransitionModel, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransitionModel::class, 'workflow_id')->orderBy('position');
    }

    public function initialState(): ?WorkflowStateModel
    {
        return $this->states()->where('is_initial', true)->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Work\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Domain\Work\StateCategory;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The core entity (docs/02 §2).
 *
 * A "task" is a work item with type = 'task'. Request, incident, review, and
 * campaign are siblings. Everything cross-cutting hangs off this one model, so
 * a new work type inherits assignment, comments, attachments, activity,
 * dependencies, hierarchy, and search for free.
 *
 * @method static Builder<WorkItemModel> open()
 * @method static Builder<WorkItemModel> overdue()
 * @method static Builder<WorkItemModel> assignedTo(string $membershipId, string $role = 'assignee')
 * @method static Builder<WorkItemModel> matching(string $terms)
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $type
 * @property string $reference
 * @property string $title
 * @property string $description
 * @property string|null $project_id
 * @property string|null $parent_id
 * @property string|null $milestone_id
 * @property string|null $created_by_membership_id
 * @property string $workflow_id
 * @property string $workflow_state_id
 * @property string $state_category
 * @property string $priority
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_at
 * @property string|null $estimate_hours
 * @property string $actual_hours_cache
 * @property string $position
 * @property string|null $recurrence_id
 * @property int $lock_version
 * @property mixed $search_vector
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class WorkItemModel extends TenantModel
{
    use SoftDeletes;

    protected $table = 'work_items';

    public const TYPES = [
        'task', 'request', 'approval_work', 'incident', 'review', 'campaign', 'operational',
    ];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'estimate_hours' => 'decimal:2',
            'actual_hours_cache' => 'decimal:2',
            'position' => 'float',
            'lock_version' => 'integer',
        ];
    }

    /**
     * The search vector is a generated column — Postgres maintains it and
     * refuses direct writes. Hiding it here keeps it out of every payload.
     *
     * @var list<string>
     */
    protected $hidden = ['search_vector'];

    // ── relations ───────────────────────────────────────────────────────────

    /** @return BelongsTo<ProjectModel, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ProjectModel::class, 'project_id');
    }

    /** @return BelongsTo<MilestoneModel, $this> */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(MilestoneModel::class, 'milestone_id');
    }

    /** @return BelongsTo<WorkItemModel, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<WorkItemModel, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /*
     * `state` is declared from WorkflowServiceProvider, not here.
     *
     * The state belongs to Workflow, and Work may not depend on Workflow —
     * that import is what made the module graph a cycle (ADR 0002). Attaching
     * the relation from the owning module's provider keeps every existing
     * `->with('state')` working while the arrow points the way docs/04 §3 says
     * it must. Organization does the same thing to hang employeeProfile off
     * Identity's membership.
     */

    /** @return BelongsTo<MembershipModel, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'created_by_membership_id');
    }

    /** @return HasMany<TimeEntryModel, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntryModel::class, 'work_item_id');
    }

    /**
     * Only the people currently on this item.
     *
     * @return HasMany<WorkItemAssignmentModel, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(WorkItemAssignmentModel::class, 'work_item_id')
            ->whereNull('unassigned_at');
    }

    /**
     * Including closed rows — this is the narrative: who had it, who handed it
     * over, and why (docs/02 §6). Never filtered out by default anywhere the
     * user is looking at history.
     *
     * @return HasMany<WorkItemAssignmentModel, $this>
     */
    public function assignmentHistory(): HasMany
    {
        return $this->hasMany(WorkItemAssignmentModel::class, 'work_item_id')
            ->orderBy('assigned_at');
    }

    /** @return HasMany<WorkItemDependencyModel, $this> */
    public function dependencies(): HasMany
    {
        return $this->hasMany(WorkItemDependencyModel::class, 'work_item_id');
    }

    /** @return HasMany<WorkItemDependencyModel, $this> */
    public function dependents(): HasMany
    {
        return $this->hasMany(WorkItemDependencyModel::class, 'depends_on_work_item_id');
    }

    // ── scopes ──────────────────────────────────────────────────────────────

    /** @param Builder<WorkItemModel> $query
     *  @return Builder<WorkItemModel> */
    /**
     * @param  Builder<WorkItemModel>  $query
     * @return Builder<WorkItemModel>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('state_category', StateCategory::CLOSED);
    }

    /** @param Builder<WorkItemModel> $query
     *  @return Builder<WorkItemModel> */
    /**
     * @param  Builder<WorkItemModel>  $query
     * @return Builder<WorkItemModel>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('state_category', StateCategory::CLOSED);
    }

    /**
     * Assigned to a specific person right now.
     *
     * Uses whereExists rather than a join so the row is not duplicated when
     * someone holds several roles on the same item.
     */
    /** @param Builder<WorkItemModel> $query
     *  @return Builder<WorkItemModel> */
    /**
     * @param  Builder<WorkItemModel>  $query
     * @return Builder<WorkItemModel>
     */
    public function scopeAssignedTo(Builder $query, string $membershipId, string $role = 'assignee'): Builder
    {
        return $query->whereExists(
            fn ($sub) => $sub
                ->from('work_item_assignments')
                ->whereColumn('work_item_assignments.work_item_id', 'work_items.id')
                ->where('membership_id', $membershipId)
                ->where('role', $role)
                ->whereNull('unassigned_at')
        );
    }

    /**
     * Full-text search over the generated tsvector.
     *
     * `websearch_to_tsquery` accepts what people actually type — quoted
     * phrases, OR, minus — rather than requiring tsquery syntax.
     *
     * @param  Builder<WorkItemModel>  $query
     * @return Builder<WorkItemModel>
     */
    public function scopeMatching(Builder $query, string $terms): Builder
    {
        return $query
            ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", [$terms])
            ->orderByRaw(
                "ts_rank(search_vector, websearch_to_tsquery('english', ?)) DESC",
                [$terms]
            );
    }

    // ── derived state ───────────────────────────────────────────────────────

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && ! in_array($this->state_category, StateCategory::CLOSED, strict: true);
    }

    public function isClosed(): bool
    {
        return in_array($this->state_category, StateCategory::CLOSED, strict: true);
    }

    /** The person currently doing the work, if anyone. */
    public function primaryAssignment(): ?WorkItemAssignmentModel
    {
        return $this->assignments
            ->firstWhere(fn (WorkItemAssignmentModel $a) => $a->role === 'assignee' && $a->is_primary);
    }
}

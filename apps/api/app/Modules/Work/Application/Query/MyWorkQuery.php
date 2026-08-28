<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Query;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Domain\Work\StateCategory;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * "What should I do now?" — answered in one query per view.
 *
 * This is a DOMAIN query, not a UI-shaped endpoint (docs/05 §2): "my work" is
 * real vocabulary in this system, so it earns a query object. What it must not
 * become is a bag of whatever the current home screen happens to render.
 *
 * Every view here is a filter over the same visible set, so a work item can
 * never appear in one view and be forbidden in another.
 */
final class MyWorkQuery
{
    public const VIEWS = [
        'today', 'upcoming', 'overdue', 'assigned',
        'created', 'awaiting_review', 'waiting_on_others', 'completed',
    ];

    public function __construct(
        private readonly WorkItemVisibility $visibility,
        private readonly TenantContext $tenant,
    ) {}

    /** @return Builder<WorkItemModel> */
    public function forView(string $view): Builder
    {
        $membershipId = $this->tenant->membershipId();

        $query = WorkItemModel::query()
            // Eager loaded here rather than in the controller: every row of
            // every view renders these, and a query-count test asserts the
            // total stays bounded (docs/11 §3).
            ->with(['state', 'project:id,key,name', 'assignments.membership.user:id,name,avatar_path'])
            ->whereNull('deleted_at');

        $this->visibility->apply($query);

        return match ($view) {
            // Everything actionable that is due today or already late. Overdue
            // work belongs HERE, not only in its own tab: a person who never
            // opens "overdue" would otherwise never see it.
            'today' => $query
                ->assignedTo($membershipId)
                ->open()
                ->whereNotNull('due_at')
                ->where('due_at', '<', now()->endOfDay())
                ->orderBy('due_at'),

            'upcoming' => $query
                ->assignedTo($membershipId)
                ->open()
                ->whereNotNull('due_at')
                ->where('due_at', '>', now()->endOfDay())
                ->where('due_at', '<=', now()->addDays(14))
                ->orderBy('due_at'),

            'overdue' => $query
                ->assignedTo($membershipId)
                ->overdue()
                ->orderBy('due_at'),

            'assigned' => $query
                ->assignedTo($membershipId)
                ->open()
                ->orderByRaw('due_at IS NULL, due_at')
                ->orderBy('priority'),

            'created' => $query
                ->where('created_by_membership_id', $membershipId)
                ->open()
                ->orderByDesc('created_at'),

            // Work waiting for THIS person to review — the queue a manager
            // most often forgets they are blocking.
            'awaiting_review' => $query
                ->assignedTo($membershipId, 'reviewer')
                ->where('state_category', 'in_review')
                ->orderBy('updated_at'),

            // Work this person submitted and cannot progress. Half of anyone's
            // frustration is work they are blocked on, and no todo list shows
            // it (docs/08 §3).
            'waiting_on_others' => $query
                ->assignedTo($membershipId)
                ->whereIn('state_category', ['in_review', 'blocked'])
                ->orderBy('updated_at'),

            'completed' => $query
                ->assignedTo($membershipId)
                ->where('state_category', 'done')
                ->where('completed_at', '>=', now()->subDays(30))
                ->orderByDesc('completed_at'),

            default => throw new \InvalidArgumentException("Unknown My Work view: {$view}"),
        };
    }

    /**
     * The counts behind the view tabs.
     *
     * One grouped query rather than eight COUNT(*) round trips — this runs on
     * every page load in the application shell.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $membershipId = $this->tenant->membershipId();

        $base = WorkItemModel::query()->whereNull('deleted_at')->assignedTo($membershipId);
        $this->visibility->apply($base);

        $rows = (clone $base)
            ->selectRaw(<<<'SQL'
                count(*) FILTER (
                    WHERE due_at < now() AND state_category NOT IN ('done','cancelled')
                ) AS overdue,
                count(*) FILTER (
                    WHERE due_at::date = current_date AND state_category NOT IN ('done','cancelled')
                ) AS due_today,
                count(*) FILTER (
                    WHERE state_category NOT IN ('done','cancelled')
                ) AS open,
                count(*) FILTER (
                    WHERE state_category IN ('in_review','blocked')
                ) AS waiting
            SQL)
            ->first();

        return [
            'overdue' => (int) ($rows->overdue ?? 0),
            'due_today' => (int) ($rows->due_today ?? 0),
            'open' => (int) ($rows->open ?? 0),
            'waiting_on_others' => (int) ($rows->waiting ?? 0),
        ];
    }

    /**
     * Items assigned but never acknowledged.
     *
     * A distinct signal from "in progress": three days of silence on an
     * unaccepted assignment is the earliest warning a manager gets.
     */
    /** @return Builder<WorkItemModel> */
    public function unacceptedAssignments(): Builder
    {
        $query = WorkItemModel::query()
            ->with(['state', 'project:id,key,name'])
            ->whereNull('deleted_at')
            ->whereNotIn('state_category', StateCategory::CLOSED)
            ->whereExists(fn ($sub) => $sub
                ->from('work_item_assignments')
                ->whereColumn('work_item_assignments.work_item_id', 'work_items.id')
                ->where('membership_id', $this->tenant->membershipId())
                ->where('role', 'assignee')
                ->whereNull('unassigned_at')
                ->whereNull('accepted_at'))
            ->orderBy('created_at');

        $this->visibility->apply($query);

        return $query;
    }
}

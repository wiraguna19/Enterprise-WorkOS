<?php

declare(strict_types=1);

namespace App\Modules\Insights\Http\Controller;

use App\Modules\Insights\Application\Query\AtRiskQuery;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemAssignmentModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Http\Request;

/**
 * "Where is the risk?" — the top half of Manager Home (docs/08 §3, ADR 0009).
 *
 * Every row states why it is here. There is no risk score and no "total tasks"
 * tile: no manager has ever made a decision from either.
 *
 * An empty list is the honest answer for someone with no reports and no project
 * they manage, and it is how the page decides not to render Manager Home at all
 * — the screen appears because there is something to manage, not because a role
 * is called "manager" (ADR 0009).
 */
final class AtRiskController extends ApiController
{
    /** Enough to see the shape of a bad week; more is a list nobody reads. */
    private const DEFAULT_LIMIT = 12;

    public function __construct(
        private readonly AtRiskQuery $atRisk,
        private readonly WorkItemVisibility $visibility,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        $rows = $this->atRisk->forManager($this->tenant->membershipId(), $limit);

        /** @var array<string, array{reasons: list<string>, blocking_count: int, days_since_move: int}> $byId */
        $byId = [];
        $order = [];

        foreach ($rows as $row) {
            $byId[$row['work_item_id']] = [
                'reasons' => $row['reasons'],
                'blocking_count' => $row['blocking_count'],
                'days_since_move' => $row['days_since_move'],
            ];

            $order[] = $row['work_item_id'];
        }

        $query = WorkItemModel::query()
            ->with(['project:id,key,name', 'assignments.membership.user:id,name'])
            ->whereIn('id', $order);

        // Applied even though the scope is already the reader's own reports and
        // projects: visibility is expressed once and applied by every read
        // (docs/06 §2), and a query that reasons its own way to "this caller
        // can see these anyway" is the fifteenth endpoint that gets it wrong.
        $this->visibility->apply($query);

        $items = $query->get()->keyBy(fn (WorkItemModel $item): string => (string) $item->getKey());

        // Re-ordered to the query's ranking: `whereIn` returns rows in no
        // particular order, and the ranking is the entire point of this list.
        $rows = [];

        foreach ($order as $id) {
            $item = $items->get($id);

            if ($item === null) {
                continue;
            }

            // The relation is already scoped to active assignments; `assignee`
            // rather than reviewer or watcher is the person the row is about.
            $assignee = $item->assignments
                ->first(fn (WorkItemAssignmentModel $assignment): bool => $assignment->role === 'assignee');

            $rows[] = [
                'id' => $id,
                'reference' => (string) $item->reference,
                'title' => (string) $item->title,
                'state_category' => (string) $item->state_category,
                'project' => $item->project?->key,
                'due_at' => $item->due_at?->toIso8601String(),
                'assignee' => $assignee?->membership?->user?->name,
                // Why this row is here. Every one of them, worst first.
                'reasons' => $byId[$id]['reasons'],
                'blocking_count' => $byId[$id]['blocking_count'],
                'days_since_move' => $byId[$id]['days_since_move'],
            ];
        }

        return ApiResponse::collection($rows, [
            'hidden_count' => count($order) - count($rows),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Insights\Http\Controller;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Insights\Application\Query\ProjectHealthQuery;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * How one project is doing, and why (ADR 0008).
 *
 * Addressed by key like every other project route, rather than by id as
 * `docs/05` writes it — one endpoint addressing projects differently would be a
 * trap rather than a convention. The deviation is in the ADR.
 */
final class ProjectHealthController extends ApiController
{
    /** The signals whose records can be listed, and the predicate for each. */
    private const SIGNALS = ['overdue', 'blocked', 'open', 'stale'];

    public function __construct(
        private readonly ProjectHealthQuery $health,
        private readonly WorkItemVisibility $visibility,
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
    ) {}

    public function show(string $key): ApiResponse
    {
        $project = $this->project($key);

        return $this->ok(
            $this->health->forProject((string) $project->getKey(), $project->end_date)
        );
    }

    /**
     * The records behind one signal (docs/10, Phase 6 exit criteria).
     *
     * Filtered by the caller's visibility with `hidden_count` returned, while
     * the counts on the health payload are not filtered at all. Two different
     * questions: how the project is doing is a fact about the project, and what
     * may be read is a fact about the reader. A count that shrank with the
     * reader would make two managers' status reports disagree about the same
     * project.
     */
    public function items(Request $request, string $key): ApiResponse
    {
        $project = $this->project($key);

        $validated = $request->validate([
            'signal' => ['required', 'string', 'in:'.implode(',', self::SIGNALS)],
        ]);

        $signal = (string) $validated['signal'];

        // Counted before the reader's visibility narrows it, so `total` is the
        // same number the signal reported and `hidden_count` reconciles the two.
        $total = $this->matching($signal, (string) $project->getKey())->count();

        $visible = $this->visibility
            ->apply($this->matching($signal, (string) $project->getKey()))
            ->with(['project:id,key,name'])
            ->orderByRaw('due_at asc nulls last')
            ->orderBy('reference')
            ->get();

        $items = $visible->map(fn (WorkItemModel $item): array => [
            'id' => (string) $item->getKey(),
            'reference' => (string) $item->reference,
            'title' => (string) $item->title,
            'state_category' => (string) $item->state_category,
            'project' => $item->project?->key,
            'due_at' => $item->due_at?->toIso8601String(),
        ])->values()->all();

        return ApiResponse::collection($items, [
            'signal' => $signal,
            'project' => (string) $project->key,
            // The count the signal reported, before this reader's visibility.
            'total' => $total,
            'hidden_count' => $total - $visible->count(),
        ]);
    }

    /**
     * @return Builder<WorkItemModel>
     */
    private function matching(string $signal, string $projectId): Builder
    {
        $query = WorkItemModel::query()
            ->where('project_id', $projectId)
            ->when($signal !== 'blocked', fn (Builder $q): Builder => $q
                ->whereNotIn('state_category', ['done', 'cancelled']));

        // Each arm restates the SQL the count came from. That duplication is
        // the risk this drill-through carries — a list that does not match the
        // number that opened it is worse than no list — so every predicate here
        // has a test asserting the two agree (ADR 0008).
        match ($signal) {
            'overdue' => $query->whereNotNull('due_at')->where('due_at', '<', now()),
            'blocked' => $query->where('state_category', 'blocked'),
            'stale' => $query->whereRaw(
                '(SELECT coalesce(max(t.occurred_at), work_items.created_at)
                    FROM work_item_transitions t
                   WHERE t.work_item_id = work_items.id
                     AND t.organization_id = work_items.organization_id)
                 < now() - (? || \' days\')::interval',
                [ProjectHealthQuery::ACTIVITY_OFF_TRACK_DAYS]
            ),
            default => null,
        };

        return $query;
    }

    private function project(string $key): ProjectModel
    {
        /** @var MembershipModel $actor */
        $actor = $this->acting->getOrFail();

        // The same visibility the project directory applies: a private project
        // is not merely unauthorized, it is not there (ADR 0004).
        $project = ProjectModel::query()
            ->visibleTo(
                $this->tenant->membershipId(),
                $this->permissions->has($actor, 'project.view_all'),
            )
            ->where('key', mb_strtoupper($key))
            ->firstOrFail();

        // Per record, not by permission alone: a project owner or manager
        // reaches their own project without holding the organization-wide
        // ability, and a private project stays private (ADR 0004).
        $this->authorize('view', $project);

        return $project;
    }
}

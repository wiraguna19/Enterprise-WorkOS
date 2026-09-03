<?php

declare(strict_types=1);

namespace App\Modules\Insights\Http\Controller;

use App\Modules\Insights\Application\Query\BottleneckQuery;
use App\Modules\Insights\Application\Query\FlowQuery;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * How work is flowing (ADR 0007, docs/08 §3).
 *
 * Aggregate only, and by period or project — never by person. The absence of an
 * assignee filter is the feature: `docs/02` §11 rules out reducing individual
 * performance to a ranked number, and per-person cycle time is that number.
 */
final class FlowController extends ApiController
{
    /** A quarter of weeks: enough to see a trend, short enough to mean today. */
    private const DEFAULT_WEEKS = 12;

    public function __construct(
        private readonly FlowQuery $flow,
        private readonly BottleneckQuery $bottlenecks,
        private readonly WorkItemVisibility $visibility,
    ) {}

    /**
     * Where work waited (ADR 0010).
     *
     * Its own endpoint rather than another key on the flow payload: this is
     * computed from every transition in the window, not folded from the
     * completions, so bundling the two would make one page load pay for a query
     * it may not be showing.
     */
    public function bottlenecks(Request $request): ApiResponse
    {
        [$from, $to] = $this->window($request);

        return $this->ok($this->bottlenecks->between($from, $to));
    }

    public function index(Request $request): ApiResponse
    {
        [$from, $to] = $this->window($request);

        return $this->ok($this->flow->between(
            $from,
            $to,
            $this->project($request),
            $this->department($request),
        ));
    }

    /**
     * The completions behind the numbers (docs/10, Phase 6 exit criteria).
     *
     * Filtered by the caller's visibility, and the count of what was withheld
     * is returned — the aggregate above is a fact about the organization's
     * delivery, while what may be READ is a fact about the reader. Same split
     * as the workload drill-through, for the same reason.
     */
    public function items(Request $request): ApiResponse
    {
        [$from, $to] = $this->window($request);

        $completions = $this->flow->completions(
            $from,
            $to,
            $this->project($request),
            $this->department($request),
        );

        // The same predicate the figure was computed from, applied to the same
        // fold — not a second "what does late mean" written on this side
        // (ADR 0010, and the rule the flow drill-through already follows).
        if ($request->boolean('late')) {
            $completions = array_values(array_filter(
                $completions,
                static fn (array $row): bool => $row['late'] === true,
            ));
        }

        /** @var array<string, array{completed_at: string, hours: float|null, late: bool|null}> $byId */
        $byId = [];

        foreach ($completions as $row) {
            $byId[$row['work_item_id']] = [
                'completed_at' => $row['completed_at'],
                'hours' => $row['hours'],
                'late' => $row['late'],
            ];
        }

        $query = WorkItemModel::query()
            ->with(['project:id,key,name'])
            ->whereIn('id', array_keys($byId));

        $this->visibility->apply($query);

        $visible = $query->get();

        // `whereIn` promises no order at all, so without this the list arrives
        // in whatever order Postgres found the rows — which changes between
        // runs and makes a drill-through look like it is shuffling itself.
        // Newest first: the question that brings someone here is "what went
        // out", and the answer starts at the most recent.
        $visible = $visible->sortByDesc(
            fn (WorkItemModel $item): string => $byId[(string) $item->getKey()]['completed_at'],
        );

        $items = $visible->map(fn (WorkItemModel $item): array => [
            'id' => (string) $item->getKey(),
            'reference' => (string) $item->reference,
            'title' => (string) $item->title,
            'project' => $item->project?->key,
            'completed_at' => $byId[(string) $item->getKey()]['completed_at'],
            // Null where the item has no `in_progress` transition to measure
            // from: nothing to measure is not the same as zero (ADR 0007).
            'cycle_time_hours' => $byId[(string) $item->getKey()]['hours'],
            // Null where the item had no due date to miss.
            'late' => $byId[(string) $item->getKey()]['late'],
        ])->values()->all();

        return ApiResponse::collection($items, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'throughput' => count($byId),
            'late_only' => $request->boolean('late'),
            'hidden_count' => count($byId) - $visible->count(),
        ]);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function window(Request $request): array
    {
        $to = $request->filled('to')
            ? CarbonImmutable::parse((string) $request->input('to'))->endOfDay()
            : CarbonImmutable::now()->endOfWeek();

        $from = $request->filled('from')
            ? CarbonImmutable::parse((string) $request->input('from'))->startOfDay()
            : $to->subWeeks(self::DEFAULT_WEEKS)->startOfWeek();

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    private function project(Request $request): ?string
    {
        $validated = $request->validate(['project_id' => ['sometimes', 'uuid']]);

        return $validated['project_id'] ?? null;
    }

    private function department(Request $request): ?string
    {
        $validated = $request->validate(['department_id' => ['sometimes', 'uuid']]);

        return $validated['department_id'] ?? null;
    }
}

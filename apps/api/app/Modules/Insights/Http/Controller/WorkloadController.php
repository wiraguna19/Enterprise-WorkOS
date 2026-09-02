<?php

declare(strict_types=1);

namespace App\Modules\Insights\Http\Controller;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Insights\Application\Query\WorkloadQuery;
use App\Modules\Organization\Application\Query\ReportingLine;
use App\Modules\Organization\Infrastructure\Eloquent\TeamModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Workload for one person, and for a team (docs/02 §11).
 *
 * Authorized per record, not by permission alone: `MembershipPolicy::
 * viewWorkload` lets a person see their own, anyone with
 * `person.view_workload` see everyone's, and a manager see anyone in their
 * reporting line at any depth. A manager who cannot see their report's load
 * cannot manage it.
 */
final class WorkloadController extends ApiController
{
    public function __construct(
        private readonly WorkloadQuery $workload,
        private readonly WorkItemVisibility $visibility,
        private readonly ReportingLine $reportingLine,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * The caller's own reporting line, person by person — Manager Home's
     * capacity block (docs/08 §3, ADR 0009).
     *
     * By the reporting line rather than by team, because that is who a manager
     * answers for: someone can manage four people who sit in three teams, and
     * a capacity block organised around teams would show them three partial
     * pictures and no whole one.
     *
     * Most-committed first. The question this block answers is "who is
     * drowning", and the answer should not be somewhere in the middle of an
     * alphabetical list.
     */
    public function reports(Request $request): ApiResponse
    {
        $week = $this->week($request);
        $rows = [];
        $withheld = 0;

        $line = MembershipModel::query()
            ->with('user')
            ->whereIn('id', $this->reportingLine->below($this->tenant->membershipId()))
            ->get();

        foreach ($line as $membership) {
            if (! $request->user()?->can('viewWorkload', $membership)) {
                $withheld++;

                continue;
            }

            $rows[] = [
                'membership_id' => (string) $membership->getKey(),
                'name' => $membership->user?->name,
            ] + $this->workload->forMembership((string) $membership->getKey(), $week);
        }

        usort($rows, static fn (array $a, array $b): int => ($b['utilization'] ?? 0) <=> ($a['utilization'] ?? 0));

        return ApiResponse::collection($rows, [
            'week_start' => $week->toDateString(),
            'withheld_count' => $withheld,
        ]);
    }

    public function person(Request $request, MembershipModel $membership): ApiResponse
    {
        $this->authorize('viewWorkload', $membership);

        return $this->ok(
            $this->workload->forMembership((string) $membership->getKey(), $this->week($request))
        );
    }

    /**
     * The items behind one person's number (docs/10, Phase 6 exit criteria).
     *
     * "A number a user cannot drill into does not ship." This is that drill,
     * and it is folded from the same computation the summary is, so the two
     * cannot disagree.
     *
     * The list is filtered by the CALLER's visibility even though the total is
     * not. Those are different questions and both answers are honest: the total
     * is a fact about the person's week, and what may be read is a fact about
     * the reader. What is not honest is a shorter list with no explanation, so
     * the hidden count is returned — and since the total is already there, the
     * hours behind it are derivable anyway. Saying so plainly beats implying it.
     */
    public function personItems(Request $request, MembershipModel $membership): ApiResponse
    {
        $this->authorize('viewWorkload', $membership);

        $week = $this->week($request);
        $breakdown = $this->workload->breakdownFor((string) $membership->getKey(), $week);

        /** @var array<string, array{share: float|null, estimated: bool}> $shares */
        $shares = [];

        foreach ($breakdown as $row) {
            $shares[$row['item']->id] = ['share' => $row['share'], 'estimated' => $row['estimated']];
        }

        $query = WorkItemModel::query()
            ->with(['project:id,key,name'])
            ->whereIn('id', array_keys($shares));

        $this->visibility->apply($query);

        $visible = $query->get();

        $items = $visible->map(fn (WorkItemModel $item): array => [
            'id' => (string) $item->getKey(),
            'reference' => (string) $item->reference,
            'title' => (string) $item->title,
            'state_category' => (string) $item->state_category,
            'project' => $item->project?->key,
            'start_date' => $item->start_date?->toDateString(),
            'due_at' => $item->due_at?->toIso8601String(),
            'estimate_hours' => $item->estimate_hours === null ? null : (float) $item->estimate_hours,
            // What this item actually contributed to the figure — the number
            // the drill-through exists to explain.
            'share_hours' => $shares[(string) $item->getKey()]['share'] === null
                ? null
                : round((float) $shares[(string) $item->getKey()]['share'], 2),
            'counted_at_default' => $shares[(string) $item->getKey()]['estimated'],
        ])->values()->all();

        return ApiResponse::collection($items, [
            'week_start' => $week->toDateString(),
            'hidden_count' => count($shares) - $visible->count(),
        ] + $this->workload->forMembership((string) $membership->getKey(), $week));
    }

    /**
     * A team's week, person by person.
     *
     * Rows, never a single team number. A team's capacity is not a pool — an
     * over-committed person next to an idle one averages to "fine", which is
     * the one reading of the data that helps nobody (docs/02 §11).
     *
     * Members whose workload the caller may not see are omitted rather than
     * zeroed, and counted, so the list never silently misrepresents the team.
     */
    public function team(Request $request, TeamModel $team): ApiResponse
    {
        $this->authorize('view', $team);

        $week = $this->week($request);
        $rows = [];
        $withheld = 0;

        $members = $team->members()->with('membership.user')->get();

        foreach ($members as $member) {
            $membership = $member->membership;

            if ($membership === null) {
                continue;
            }

            if (! $request->user()?->can('viewWorkload', $membership)) {
                $withheld++;

                continue;
            }

            $rows[] = [
                'name' => $membership->user?->name,
            ] + $this->workload->forMembership((string) $membership->getKey(), $week);
        }

        return ApiResponse::collection($rows, [
            'week_start' => $week->toDateString(),
            'withheld_count' => $withheld,
        ]);
    }

    /**
     * The week being asked about, always starting on a Monday.
     *
     * Snapped rather than validated: a caller passing a Thursday means "the
     * week containing that Thursday", and refusing it would be pedantry about a
     * question with an obvious answer.
     */
    private function week(Request $request): CarbonImmutable
    {
        $anchor = $request->filled('week')
            ? CarbonImmutable::parse((string) $request->input('week'))
            : CarbonImmutable::now();

        return $anchor->startOfWeek();
    }
}

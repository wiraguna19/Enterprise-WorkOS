<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Report;

use App\Modules\Insights\Application\Query\WorkloadQuery;
use App\Modules\Organization\Infrastructure\Eloquent\TeamModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * What a team is carrying this week, one row per person (docs/02 §11).
 *
 * Rows, never a team total — an over-committed person beside an idle one
 * averages to "fine", which is the one reading of this data that helps nobody.
 * The columns are exactly the figures the workload endpoint returns, including
 * the two honesty flags, because a capacity number without them is a number
 * somebody will make a staffing decision from and be wrong.
 *
 * This is operational capacity signal and not a performance ranking. There is
 * no throughput column, no "items completed", and nothing here sorts people by
 * output — `docs/02` §11 rules that out, and a spreadsheet is exactly where an
 * innocent-looking extra column becomes a league table.
 */
final class TeamReport implements ReportBuilder
{
    public function __construct(private readonly WorkloadQuery $workload) {}

    public function columns(): array
    {
        return [
            'name', 'week_start', 'capacity_hours', 'committed_hours', 'utilization',
            'item_count', 'unestimated_count', 'undated_count',
        ];
    }

    public function build(array $parameters): array
    {
        $team = TeamModel::query()->find((string) ($parameters['team'] ?? ''));

        if ($team === null) {
            throw new ModelNotFoundException('No such team.');
        }

        Gate::authorize('view', $team);

        $week = isset($parameters['week'])
            ? CarbonImmutable::parse((string) $parameters['week'])->startOfWeek()
            : CarbonImmutable::now()->startOfWeek();

        $rows = [];
        $withheld = 0;

        foreach ($team->members()->with('membership.user')->get() as $member) {
            $membership = $member->membership;

            if ($membership === null) {
                continue;
            }

            // Per record, exactly as the screen does it: a manager sees their
            // reporting line, everyone else needs the organization-wide
            // ability. Someone whose workload this reader may not see is
            // COUNTED and not listed, rather than quietly missing.
            if (! Gate::allows('viewWorkload', $membership)) {
                $withheld++;

                continue;
            }

            $workload = $this->workload->forMembership((string) $membership->getKey(), $week);

            $rows[] = [
                $membership->user?->name,
                $workload['week_start'],
                $workload['capacity_hours'],
                $workload['committed_hours'],
                $workload['utilization'],
                $workload['item_count'],
                $workload['unestimated_count'],
                $workload['undated_count'],
            ];
        }

        return ['rows' => $rows, 'hidden_count' => $withheld];
    }
}

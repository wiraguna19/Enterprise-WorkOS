<?php

declare(strict_types=1);

namespace App\Modules\Insights\Http\Controller;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Insights\Application\Query\WorkloadQuery;
use App\Modules\Organization\Infrastructure\Eloquent\TeamModel;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
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
    public function __construct(private readonly WorkloadQuery $workload) {}

    public function person(Request $request, MembershipModel $membership): ApiResponse
    {
        $this->authorize('viewWorkload', $membership);

        return $this->ok(
            $this->workload->forMembership((string) $membership->getKey(), $this->week($request))
        );
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

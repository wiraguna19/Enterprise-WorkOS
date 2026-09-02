<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Query;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Organization\Application\Query\ReportingLine;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who can see which work item (docs/06 §2).
 *
 * Expressed ONCE, as a query scope, and applied by every list and detail read.
 * Two properties matter and neither is optional:
 *
 *   1. It runs IN the query, not after fetching. Filtering after the fact
 *      breaks pagination (page 2 of 50 rows may return 31) and leaks row counts.
 *   2. It lives here, not in each endpoint. Implementing visibility per
 *      endpoint is how a leak eventually ships — the fifteenth endpoint is the
 *      one that forgets.
 *
 * The tenant scope has already made other organizations unreachable; this is
 * strictly about visibility WITHIN one organization.
 */
final class WorkItemVisibility
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
        private readonly ReportingLine $reportingLine,
    ) {}

    /**
     * @param  Builder<WorkItemModel>  $query
     * @return Builder<WorkItemModel>
     */
    public function apply(Builder $query): Builder
    {
        $membershipId = $this->tenant->membershipId();

        // The same row every policy and permission check needs, read once per
        // request rather than once per call site.
        $actor = $this->acting->getOrFail();

        // Someone who can see every project sees all its work. They still
        // cannot see PRIVATE projects they are not a member of — that is the
        // point of marking a project private.
        $canViewAll = $this->permissions->has($actor, 'project.view_all');

        return $query->where(function (Builder $q) use ($membershipId, $canViewAll): void {
            // 1. Work in a project the actor can reach.
            $q->whereExists(fn ($sub) => $sub
                ->from('projects')
                ->whereColumn('projects.id', 'work_items.project_id')
                ->whereNull('projects.deleted_at')
                ->where(function ($p) use ($membershipId, $canViewAll): void {
                    if ($canViewAll) {
                        $p->where('projects.visibility', 'internal');
                    }

                    $p->orWhere('projects.owner_membership_id', $membershipId)
                        ->orWhereExists(fn ($m) => $m
                            ->from('project_members')
                            ->whereColumn('project_members.project_id', 'projects.id')
                            ->whereNull('project_members.removed_at')
                            ->where(fn ($w) => $w
                                ->where('project_members.membership_id', $membershipId)
                                ->orWhereIn('project_members.team_id', fn ($t) => $t
                                    ->from('team_members')
                                    ->select('team_id')
                                    ->where('membership_id', $membershipId)
                                    ->whereNull('left_at'))));
                }));

            // 2. Work they are, or WERE, involved in — historical assignments
            //    included. Losing sight of work you handed over last week makes
            //    the history feature useless in practice.
            $q->orWhereExists(fn ($sub) => $sub
                ->from('work_item_assignments')
                ->whereColumn('work_item_assignments.work_item_id', 'work_items.id')
                ->where('membership_id', $membershipId));

            // 3. Work they created — including project-less work, which has no
            //    other route to visibility.
            $q->orWhere('created_by_membership_id', $membershipId);

            // 4. Work they watch.
            $q->orWhereExists(fn ($sub) => $sub
                ->from('work_item_watchers')
                ->whereColumn('work_item_watchers.work_item_id', 'work_items.id')
                ->where('membership_id', $membershipId));

            // 5. Work assigned to someone in their reporting line. A manager
            //    who cannot see their report's work cannot manage it — and the
            //    line is transitive, so a skip-level manager sees it too.
            $q->orWhereExists(fn ($sub) => $sub
                ->from('work_item_assignments as a')
                ->whereColumn('a.work_item_id', 'work_items.id')
                ->whereNull('a.unassigned_at')
                ->whereIn('a.membership_id', $this->reportingLine->below($membershipId)));
        });
    }
}

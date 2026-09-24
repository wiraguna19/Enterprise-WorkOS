<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Governance\Application\Query\ActivityFeed;
use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;

/**
 * What happened to this work item, and who did it (docs/02 §8).
 *
 * The endpoint lives in Work rather than in Governance, and that is the whole
 * design: reading a timeline is a visibility decision about the SUBJECT, and
 * Governance cannot see work items — it may depend on Platform and nothing
 * else. So Work resolves the item through its own visibility scope and its own
 * policy, then asks Governance a question that is purely about storage.
 *
 * Inverting it would mean teaching the log about every kind of subject it
 * records, which is exactly the coupling that keeps an audit trail honest by
 * not existing.
 */
final class ActivityController extends ApiController
{
    public function __construct(
        private readonly WorkItemVisibility $visibility,
        private readonly ActivityFeed $activity,
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
    ) {}

    public function index(string $reference): ApiResponse
    {
        $query = WorkItemModel::query()->where('reference', mb_strtoupper($reference));

        $this->visibility->apply($query);

        /** @var WorkItemModel $item */
        $item = $query->firstOrFail();

        $this->authorize('view', $item);

        return $this->ok($this->activity->forSubject(
            subjectType: 'work_item',
            subjectId: (string) $item->getKey(),
            // The item's own creation time. Exact, free, and the reason the
            // partitioned table can be read at all without touching every
            // partition ever created — nothing happened to this item before it
            // existed.
            since: $item->created_at ?? now()->subYears(5),
        ));
    }

    /**
     * What happened to this project, and who did it (ADR 0043).
     *
     * Written since ADR 0040 and read by nothing: renames, archives, and every
     * change to who has access all landed in `activity_logs` under
     * `subject_type = 'project'`, with no endpoint able to return one of them.
     * **A write path with no read path**, created two commits earlier by the
     * slice that added the writes — this project's most repeated defect, and
     * this time self-inflicted rather than inherited.
     *
     * It matters most for access. `project_members` keeps removed rows with a
     * `removed_at` precisely so "who could see this and when" stays answerable,
     * and until now the answer was stored and unreachable.
     *
     * Same shape as the work item timeline above, and for the same reason: the
     * visibility decision is about the SUBJECT, so Work resolves the project
     * through its own scope and policy and then asks Governance a question that
     * is purely about storage.
     */
    public function project(string $key): ApiResponse
    {
        /** @var MembershipModel $actor */
        $actor = $this->acting->getOrFail();

        $project = ProjectModel::query()
            ->visibleTo(
                $this->tenant->membershipId(),
                $this->permissions->has($actor, 'project.view_all'),
            )
            ->where('key', mb_strtoupper($key))
            ->firstOrFail();

        $this->authorize('view', $project);

        return $this->ok($this->activity->forSubject(
            subjectType: 'project',
            subjectId: (string) $project->getKey(),
            // The project's own creation time, for the reason the work item
            // timeline gives: nothing happened to it before it existed, and the
            // log is partitioned by `occurred_at`.
            //
            // No `?? now()->subYears(5)` here, unlike the work item above:
            // `projects.created_at` is NOT NULL, and a coalesce whose left side
            // cannot be null is a fallback that reads as a real case.
            since: $project->created_at,
        ));
    }
}

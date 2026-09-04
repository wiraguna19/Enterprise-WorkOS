<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Governance\Application\Query\ActivityFeed;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
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
}

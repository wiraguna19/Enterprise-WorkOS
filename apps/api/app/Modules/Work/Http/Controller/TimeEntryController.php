<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Application\Service\TimeEntryService;
use App\Modules\Work\Http\Request\LogTimeRequest;
use App\Modules\Work\Infrastructure\Eloquent\TimeEntryModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Time is logged against a work item, and read back from it (docs/03 §4).
 */
final class TimeEntryController extends ApiController
{
    public function __construct(
        private readonly TimeEntryService $time,
        private readonly WorkItemVisibility $visibility,
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
    ) {}

    public function index(string $reference): ApiResponse
    {
        $item = $this->findVisible($reference);

        $this->authorize('view', $item);

        $entries = TimeEntryModel::query()
            ->with('membership.user:id,name')
            ->where('work_item_id', $item->getKey())
            ->orderByDesc('logged_on')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::collection(
            $entries->map(fn (TimeEntryModel $entry): array => $this->present($entry))->all(),
            [
                // The rollup is returned WITH the rows it was computed from, so
                // a number that looks wrong can be checked on the spot rather
                // than reported as a mystery (docs/03 §4).
                'total_hours' => (float) $entries->sum(static fn (TimeEntryModel $e): float => (float) $e->hours),
                'cached_total' => (float) $item->actual_hours_cache,
            ],
        );
    }

    public function store(LogTimeRequest $request, string $reference): ApiResponse
    {
        $item = $this->findVisible($reference);

        $membershipId = (string) $request->input('membership_id', $this->tenant->membershipId());

        // Logging your own time needs only the ability to log time. Logging
        // SOMEONE ELSE'S is an assignment-level act: it puts hours in another
        // person's name, and their capacity report will carry them.
        if ($membershipId !== $this->tenant->membershipId()) {
            $actor = $this->acting->get();

            if ($actor === null || ! $this->permissions->has($actor, 'work_item.assign')) {
                throw new AuthorizationException('You may only log your own time.');
            }
        }

        $entry = $this->time->log(
            $item,
            $membershipId,
            (float) $request->input('hours'),
            (string) $request->input('logged_on', now()->toDateString()),
            (string) $request->input('note', ''),
        );

        return $this->created($this->present($entry->fresh(['membership.user']) ?? $entry));
    }

    /**
     * Removing an entry is removing a claim about your own hours.
     *
     * Anyone with `work_item.assign` can remove someone else's — the same trust
     * level that let them add it. Everyone else may only remove their own, and
     * a 404 rather than a 403 for a stranger's entry, because whose it is is
     * not information this endpoint owes them (docs/05 §3).
     */
    public function destroy(string $reference, string $entryId): ApiResponse
    {
        $item = $this->findVisible($reference);

        /** @var TimeEntryModel $entry */
        $entry = TimeEntryModel::query()
            ->where('work_item_id', $item->getKey())
            ->findOrFail($entryId);

        if ((string) $entry->membership_id !== $this->tenant->membershipId()) {
            $actor = $this->acting->get();

            if ($actor === null || ! $this->permissions->has($actor, 'work_item.assign')) {
                abort(404);
            }
        }

        $this->time->delete($entry);

        return $this->noContent();
    }

    /** @return array<string, mixed> */
    private function present(TimeEntryModel $entry): array
    {
        return [
            'id' => (string) $entry->getKey(),
            'hours' => (float) $entry->hours,
            'logged_on' => $entry->logged_on->toDateString(),
            'note' => (string) $entry->note,
            'membership_id' => (string) $entry->membership_id,
            'person' => $entry->membership?->user?->name,
            'logged_at' => $entry->created_at->toIso8601String(),
        ];
    }

    private function findVisible(string $reference): WorkItemModel
    {
        $query = WorkItemModel::query()->where('reference', mb_strtoupper($reference));

        $this->visibility->apply($query);

        return $query->firstOrFail();
    }
}

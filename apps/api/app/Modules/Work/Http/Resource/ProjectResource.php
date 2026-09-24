<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Resource;

use App\Modules\Platform\Http\Resource\BaseResource;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;

/**
 * @property ProjectModel $resource
 */
final class ProjectResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => 'project',
            'key' => $this->resource->key,
            'name' => $this->resource->name,
            'description' => $this->when(
                $request->routeIs('*.show'),
                fn () => $this->resource->description,
            ),
            'status' => $this->resource->status,
            'priority' => $this->resource->priority,
            'visibility' => $this->resource->visibility,
            'start_date' => $this->resource->start_date?->toDateString(),
            'end_date' => $this->resource->end_date?->toDateString(),

            'owner' => $this->whenLoaded('owner', fn () => [
                'membership_id' => $this->resource->owner?->id,
                'name' => $this->resource->owner?->user?->name,
            ]),
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->resource->department?->id,
                'name' => $this->resource->department?->name,
            ]),

            // Derived and cached, never authoritative — so the freshness is
            // reported alongside it rather than implied (docs/12 §8).
            //
            // **`null` when there is nothing to be a percentage OF** (ADR
            // 0042). The column is NOT NULL with a 0..100 CHECK, so the
            // database stores 0 for a project with no work — and 0 there means
            // "no countable work", while 0 everywhere else means "none of it
            // is done". Serving both as `0` makes the client render a
            // confident "0% complete" for a project nobody has put work in
            // yet, which is a wrong number that looks computed: the exact
            // shape this column was already caught in once.
            'progress' => $this->progressPercent(),
            'progress_as_of' => $this->resource->progress_cached_at?->toIso8601String(),

            'budget' => $this->resource->budget_amount === null ? null : [
                'amount' => (float) $this->resource->budget_amount,
                'currency' => $this->resource->budget_currency,
            ],

            'member_count' => $this->whenCounted('members'),
            'open_work_count' => $this->when(
                isset($this->resource->open_work_count),
                fn () => (int) $this->resource->open_work_count,
            ),
            'overdue_work_count' => $this->when(
                isset($this->resource->overdue_work_count),
                fn () => (int) $this->resource->overdue_work_count,
            ),

            'archived' => $this->resource->archived_at !== null,
            'lock_version' => $this->resource->lock_version,

            'permissions' => $this->permissions([
                'update' => 'update',
                'delete' => 'delete',
                'archive' => 'archive',
                'manage_members' => 'manageMembers',
                'create_work' => 'createWork',
            ]),
        ];
    }

    /**
     * The cached percentage, or null when nothing was counted.
     *
     * Only the collection query carries `countable_work_count`, so on an
     * endpoint that does not select it this answers the cached figure
     * unchanged — absent evidence is not evidence of absence, and a detail
     * payload claiming "no work" because it did not ask would be worse than
     * the 0 it replaced.
     */
    private function progressPercent(): ?float
    {
        if (isset($this->resource->countable_work_count)
            && (int) $this->resource->countable_work_count === 0
        ) {
            return null;
        }

        return (float) $this->resource->progress_cache;
    }
}

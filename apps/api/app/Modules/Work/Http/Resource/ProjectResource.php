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
            'progress' => (float) $this->resource->progress_cache,
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
}

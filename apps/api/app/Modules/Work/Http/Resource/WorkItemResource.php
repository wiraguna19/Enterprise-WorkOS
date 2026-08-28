<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Resource;

use App\Modules\Platform\Http\Resource\BaseResource;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemAssignmentModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;

/**
 * @property WorkItemModel $resource
 */
final class WorkItemResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type,
            // What people actually say out loud, and what the URL uses.
            'reference' => $this->resource->reference,
            'title' => $this->resource->title,
            'description' => $this->when(
                $request->routeIs('*.show'),
                fn () => $this->resource->description,
            ),

            'state' => $this->whenLoaded('state', fn () => [
                'id' => $this->resource->state?->id,
                'key' => $this->resource->state?->key,
                'label' => $this->resource->state?->label,
                // The client renders from the CATEGORY, never the label — that
                // is what makes renaming a state safe (docs/02 §7).
                'category' => $this->resource->state?->category,
                'color' => $this->resource->state?->color,
            ]),
            'state_category' => $this->resource->state_category,

            'priority' => $this->resource->priority,
            'start_date' => $this->resource->start_date?->toDateString(),
            'due_at' => $this->resource->due_at?->toIso8601String(),
            'is_overdue' => $this->resource->isOverdue(),
            'estimate_hours' => $this->resource->estimate_hours === null
                ? null
                : (float) $this->resource->estimate_hours,
            'position' => (float) $this->resource->position,

            'project' => $this->whenLoaded('project', fn () => $this->resource->project === null ? null : [
                'id' => $this->resource->project->id,
                'key' => $this->resource->project->key,
                'name' => $this->resource->project->name,
            ]),
            'parent_id' => $this->resource->parent_id,
            'milestone_id' => $this->resource->milestone_id,

            'assignees' => $this->whenLoaded('assignments', fn () => $this->resource->assignments
                ->map(fn (WorkItemAssignmentModel $a) => [
                    'assignment_id' => $a->id,
                    'membership_id' => $a->membership_id,
                    'name' => $a->membership?->user?->name,
                    'avatar_url' => $a->membership?->user?->avatar_path,
                    'role' => $a->role,
                    // Surfaced rather than hidden: assigned-but-unacknowledged
                    // is the state a manager needs to notice.
                    'accepted' => $a->accepted_at !== null,
                ])->values()->all()),

            'subtask_count' => $this->whenCounted('children'),
            'comment_count' => $this->whenCounted('comments'),

            'completed_at' => $this->resource->completed_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),

            // Optimistic locking: the client sends this back on update and gets
            // a 409 rather than silently overwriting someone (docs/05 §5).
            'lock_version' => $this->resource->lock_version,

            'permissions' => $this->permissions([
                'update' => 'update',
                'delete' => 'delete',
                'assign' => 'assign',
                'transition' => 'transition',
                'submit' => 'submit',
                'comment' => 'comment',
                'log_time' => 'logTime',
            ]),
        ];
    }
}

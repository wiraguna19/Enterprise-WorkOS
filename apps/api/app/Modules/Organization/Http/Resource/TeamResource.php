<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resource;

use App\Modules\Organization\Infrastructure\Eloquent\TeamModel;
use App\Modules\Platform\Http\Resource\BaseResource;

/**
 * @property TeamModel $resource
 */
final class TeamResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => 'team',
            'name' => $this->resource->name,
            'key' => $this->resource->key,
            'description' => $this->resource->description,
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->resource->department?->id,
                'name' => $this->resource->department?->name,
            ]),
            'lead_membership_id' => $this->resource->lead_membership_id,
            'member_count' => $this->whenCounted('members'),
            'members' => $this->whenLoaded('members', fn () => $this->resource->members->map(fn ($m) => [
                'membership_id' => $m->membership_id,
                'name' => $m->membership?->user?->name,
                'job_title' => $m->membership?->employeeProfile?->job_title,
                'role' => $m->role,
                'joined_at' => $m->joined_at,
            ])->all()),
            'archived' => $this->resource->archived_at !== null,
            'permissions' => $this->permissions([
                'update' => 'update',
                'manage_members' => 'manageMembers',
                'delete' => 'delete',
            ]),
        ];
    }
}

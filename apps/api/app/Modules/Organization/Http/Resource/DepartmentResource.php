<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resource;

use App\Modules\Organization\Infrastructure\Eloquent\DepartmentModel;
use App\Modules\Platform\Http\Resource\BaseResource;

/**
 * @property DepartmentModel $resource
 */
final class DepartmentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => 'department',
            'name' => $this->resource->name,
            'code' => $this->resource->code,
            'parent_id' => $this->resource->parent_id,
            'depth' => $this->resource->depth,
            'head' => $this->whenLoaded('head', fn () => [
                'membership_id' => $this->resource->head_membership_id,
            ]),
            'team_count' => $this->whenCounted('teams'),
            'archived' => $this->resource->archived_at !== null,
            // The server's decision, echoed for the client to render from
            // (docs/07 §4) — not a delegation of the decision.
            'permissions' => $this->permissions([
                'update' => 'update',
                'delete' => 'delete',
            ]),
        ];
    }
}

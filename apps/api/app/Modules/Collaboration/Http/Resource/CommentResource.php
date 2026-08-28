<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Resource;

use App\Modules\Collaboration\Infrastructure\Eloquent\CommentModel;
use App\Modules\Platform\Http\Resource\BaseResource;

/**
 * @property CommentModel $resource
 */
final class CommentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => 'comment',
            'author' => [
                'membership_id' => $this->resource->author_membership_id,
                'name' => $this->resource->author?->user?->name,
                'avatar_url' => $this->resource->author?->user?->avatar_path,
            ],
            // Both are returned: the HTML is what renders, the markdown is what
            // loads into the editor when someone clicks edit. Sending only HTML
            // would force the client to un-render it, which never round-trips.
            'body_html' => $this->resource->body_html,
            'body_markdown' => $this->resource->body_markdown,
            'parent_id' => $this->resource->parent_id,
            'edited' => $this->resource->edited_at !== null,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}

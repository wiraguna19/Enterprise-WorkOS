<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Resource;

use App\Modules\Notification\Infrastructure\Eloquent\NotificationModel;
use App\Modules\Platform\Http\Resource\BaseResource;

/**
 * Renders entirely from the stored payload snapshot — no joins to the subject.
 *
 * That is why the snapshot exists: an inbox of 50 rows would otherwise be 50
 * joins, and a notification about a deleted work item would render blank
 * instead of telling you what happened (docs/03 §5).
 *
 * @property NotificationModel $resource
 */
final class NotificationResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        $payload = (array) $this->resource->payload;

        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type,
            'subject' => [
                'type' => $this->resource->subject_type,
                'id' => $this->resource->subject_id,
                'reference' => $payload['reference'] ?? null,
                'title' => $payload['title'] ?? null,
            ],
            'actor' => [
                'membership_id' => $this->resource->actor_membership_id,
                'name' => $payload['actor_name'] ?? $this->resource->actor?->user?->name,
            ],
            'message' => $this->message($payload),
            'read' => $this->resource->read_at !== null,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    /**
     * Wording is composed server-side so it stays consistent between the inbox,
     * a future email, and a future push — three places that would otherwise
     * drift.
     *
     * @param  array<string, mixed>  $payload
     */
    private function message(array $payload): string
    {
        $actor = $payload['actor_name'] ?? 'Someone';
        $reference = $payload['reference'] ?? 'work';

        return match ($this->resource->type) {
            'work.assigned' => isset($payload['handover'])
                ? "{$actor} handed {$reference} over to you"
                : "{$actor} assigned {$reference} to you",
            'work.reassigned_away' => "{$actor} reassigned {$reference} to someone else",
            'approval.requested' => "{$actor} asked you to review {$reference}",
            'approval.approved' => "{$actor} approved {$reference}",
            'approval.changes_requested' => "{$actor} requested changes on {$reference}",
            'approval.rejected' => "{$actor} rejected {$reference}",
            'work.escalated' => "{$reference} needs attention",
            'comment.mentioned' => "{$actor} mentioned you on {$reference}",
            default => (string) ($payload['message'] ?? "Update on {$reference}"),
        };
    }
}

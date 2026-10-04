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
        $sentence = $this->sentence($payload);

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
            // The sentence in English, and WHICH sentence it is. A client in
            // another language words it from the key; one that does not know
            // the key, or gets none, shows `message` as written. No key is sent
            // for an organization's own text — a rule's message is its words.
            'message' => $sentence[1],
            'message_key' => $sentence[0],
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
     * @return array{0: string|null, 1: string}
     */
    private function sentence(array $payload): array
    {
        $reference = $payload['reference'] ?? 'work';

        /*
         * No actor means a RULE did this, not a mystery person.
         *
         * "Someone escalated ENG-142" sends people asking each other who did
         * it. The web app learned this in Phase 4 and fixed it in its own copy
         * of this sentence table — a copy that could never run, because this
         * resource does not emit the payload that copy read. So the lesson
         * lived in dead code while the sentence people actually saw still
         * invented a colleague.
         */
        if (! isset($payload['actor_name'])) {
            return match ($this->resource->type) {
                'work.escalated' => ['escalated_to_you', "{$reference} is overdue and has been escalated to you"],
                'work.needs_assignee' => ['needs_assignee', "{$reference} is urgent and has nobody on it"],
                'work.due_soon' => ['due_soon', "{$reference} is due soon"],
                default => isset($payload['message'])
                    ? [null, (string) $payload['message']]
                    : ['update', "Update on {$reference}"],
            };
        }

        $actor = $payload['actor_name'];

        return match ($this->resource->type) {
            'work.assigned' => isset($payload['handover'])
                ? ['handed_over', "{$actor} handed {$reference} over to you"]
                : ['assigned', "{$actor} assigned {$reference} to you"],
            'work.reassigned_away' => ['reassigned_away', "{$actor} reassigned {$reference} to someone else"],
            'approval.requested' => ['review_requested', "{$actor} asked you to review {$reference}"],
            'approval.approved' => ['approved', "{$actor} approved {$reference}"],
            'approval.changes_requested' => ['changes_requested', "{$actor} requested changes on {$reference}"],
            'approval.rejected' => ['rejected', "{$actor} rejected {$reference}"],
            'work.escalated' => ['needs_attention', "{$reference} needs attention"],
            'comment.mentioned' => ['mentioned', "{$actor} mentioned you on {$reference}"],
            default => isset($payload['message'])
                ? [null, (string) $payload['message']]
                : ['update', "Update on {$reference}"],
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Application\Service;

use App\Modules\Collaboration\Infrastructure\Eloquent\CommentModel;
use App\Modules\Collaboration\Infrastructure\Eloquent\MentionModel;
use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Application\Event\RecordsDomainEvents;
use App\Modules\Platform\Domain\Contract\RealtimePublisher;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Infrastructure\Realtime\Channel;
use Illuminate\Support\Facades\DB;

/**
 * Comments, with two rules that are not negotiable.
 *
 * 1. HTML is rendered SERVER-side through an allowlist and stored. The client
 *    never renders raw user markdown, and never has to trust its own sanitizer.
 *    A client-side-only sanitizer is one dependency upgrade away from an XSS
 *    (docs/06 §3).
 *
 * 2. Mentions are EXTRACTED server-side from the text, never accepted from the
 *    client. A client-supplied mention list is a notification-spam vector:
 *    anyone could notify the whole company by posting "hi" with a crafted body.
 */
final class CommentService
{
    use RecordsDomainEvents;

    public function __construct(
        private readonly MarkdownRenderer $renderer,
        private readonly ActivityLogger $activity,
        private readonly TenantContext $tenant,
        private readonly RealtimePublisher $realtime,
    ) {}

    public function create(
        string $subjectType,
        string $subjectId,
        string $markdown,
        ?string $parentId = null,
    ): CommentModel {
        return $this->transactional(function () use ($subjectType, $subjectId, $markdown, $parentId): CommentModel {
            $body = trim($markdown);

            $comment = new CommentModel;
            $id = CommentModel::newId();

            $comment->forceFill([
                'id' => $id,
                'commentable_type' => $subjectType,
                'commentable_id' => $subjectId,
                'parent_id' => $parentId,
                'author_membership_id' => $this->tenant->membershipId(),
                'body_markdown' => $body,
                'body_html' => $this->renderer->render($body),
            ])->save();

            $this->recordMentions($comment, $body);

            $this->activity->record($subjectType, $subjectId, 'commented', [
                'comment_id' => ['from' => null, 'to' => $id],
            ]);

            // Told, not sent: the id is enough for a listener to refetch the
            // thread through the API, which applies visibility. Pushing the
            // rendered body down the socket would make the channel itself the
            // thing that decides who reads it (docs/07 §8).
            if ($subjectType === 'work_item') {
                $this->realtime->publish(
                    Channel::workItem($this->tenant->organizationId(), $subjectId),
                    'comment.created',
                    ['comment_id' => $id, 'work_item_id' => $subjectId],
                );
            }

            return $comment;
        });
    }

    public function update(CommentModel $comment, string $markdown): CommentModel
    {
        return $this->transactional(function () use ($comment, $markdown): CommentModel {
            $body = trim($markdown);

            $comment->forceFill([
                'body_markdown' => $body,
                'body_html' => $this->renderer->render($body),
                'edited_at' => now(),
            ])->save();

            // Mentions are recomputed, not merged: removing a name from an
            // edited comment must remove the mention, or the notification
            // outlives the text that caused it.
            MentionModel::query()->where('comment_id', $comment->getKey())->delete();
            $this->recordMentions($comment, $body);

            return $comment;
        });
    }

    /**
     * Resolve @mentions to real memberships.
     *
     * Matching is by display name against ACTIVE members of this organization
     * only, so an @mention can never resolve across tenants or to someone who
     * has left.
     */
    private function recordMentions(CommentModel $comment, string $body): void
    {
        preg_match_all('/@([\p{L}][\p{L}\p{N}\'\-]*(?:\s+[\p{L}][\p{L}\p{N}\'\-]*)?)/u', $body, $matches);

        $names = array_unique(array_map('trim', $matches[1]));

        if ($names === []) {
            return;
        }

        $memberships = MembershipModel::query()
            ->with('user:id,name')
            ->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->whereIn(
                DB::raw('lower(name)'),
                array_map(mb_strtolower(...), $names),
            ))
            ->get();

        foreach ($memberships as $membership) {
            // Never notify someone about their own comment.
            if ((string) $membership->getKey() === $this->tenant->membershipId()) {
                continue;
            }

            $mention = new MentionModel;
            $mention->forceFill([
                'id' => MentionModel::newId(),
                'comment_id' => $comment->getKey(),
                'mentioned_membership_id' => $membership->getKey(),
            ])->save();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Application\Service;

use App\Modules\Collaboration\Infrastructure\Eloquent\CommentModel;
use App\Modules\Collaboration\Infrastructure\Eloquent\MentionModel;
use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Notification\Application\Service\NotificationDispatcher;
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
        private readonly NotificationDispatcher $notifications,
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
     *
     * **A name is however many words it is.** The first version captured one
     * word or two, which is a guess about how people are called: "@I Made
     * Wiraguna" resolved to nobody, silently, and the demo seed could never
     * have shown it because every seeded name happens to be two words. So the
     * text after each `@` is cut into candidate prefixes and the LONGEST one
     * that equals a stored name wins — the database decides what a name is,
     * and the parser stops guessing.
     *
     * Still extracted from the text, never accepted from the client: a
     * client-supplied mention list is a notification-spam vector (see the class
     * docblock), and that does not change because the client now has a picker.
     */
    private function recordMentions(CommentModel $comment, string $body): void
    {
        $occurrences = $this->candidateNames($body);

        if ($occurrences === []) {
            return;
        }

        $byName = MembershipModel::query()
            ->with('user:id,name')
            ->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->whereIn(
                DB::raw('lower(name)'),
                array_merge(...$occurrences),
            ))
            ->get()
            ->keyBy(fn (MembershipModel $m) => mb_strtolower((string) $m->user?->name));

        /** @var list<string> $mentioned */
        $mentioned = [];

        foreach ($occurrences as $prefixes) {
            // Longest first, and STOP at the first hit. With a "Rina" and a
            // "Rina Wijaya" in the same organization, "@Rina Wijaya" means one
            // of them — notifying both because both prefixes matched is the
            // kind of helpfulness people turn notifications off over.
            foreach (array_reverse($prefixes) as $name) {
                $membership = $byName->get($name);

                if ($membership === null) {
                    continue;
                }

                // Never notify someone about their own comment.
                if ((string) $membership->getKey() === $this->tenant->membershipId()) {
                    break;
                }

                if (in_array((string) $membership->getKey(), $mentioned, strict: true)) {
                    break;   // named twice in one comment; one row, one notification
                }

                $mention = new MentionModel;
                $mention->forceFill([
                    'id' => MentionModel::newId(),
                    'comment_id' => $comment->getKey(),
                    'mentioned_membership_id' => $membership->getKey(),
                ])->save();

                $mentioned[] = (string) $membership->getKey();

                break;
            }
        }

        if ($mentioned === []) {
            return;
        }

        /*
         * Told, not just recorded.
         *
         * The mention rows have been written since Phase 2 and nothing ever
         * read them: `comment.mentioned` had a sentence in the resource, a
         * toggle on the settings screen, and no code path that produced one.
         * A write with no reader (docs/11 §7) — and the quiet half of docs/11
         * §4 flow 6.
         *
         * Dispatched here rather than through an event a listener picks up,
         * because Collaboration and Notification are siblings and the sideways
         * subscription would close a cycle through Workflow (ADR 0013). This is
         * the same call Workflow's NotifyAction makes.
         */
        $this->notifications->dispatch(
            type: 'comment.mentioned',
            subjectType: $comment->commentable_type,
            subjectId: $comment->commentable_id,
            recipients: $mentioned,
            payload: ['comment_id' => $comment->getKey()],
            dedupeSeed: (string) $comment->getKey(),
        );
    }

    /**
     * Every name that could have been meant, from every `@` in the text.
     *
     * For "@I Made Wiraguna please look" this yields "i", "i made", "i made
     * wiraguna", "i made wiraguna please" and so on up to the word cap — and
     * the query keeps whichever of them is an actual member's name. Sending a
     * handful of prefixes to an exact-match lookup is cheaper than fetching
     * every member to compare in PHP, and it cannot match a name that is not
     * stored.
     *
     * MAX_NAME_WORDS bounds the work: a comment full of "@" would otherwise
     * generate prefixes without limit. Six is past any name this product has
     * seen and far below anything that costs.
     *
     * @return list<list<string>> one list per `@`, lowercased, shortest first
     */
    private function candidateNames(string $body): array
    {
        $maxWords = 6;

        preg_match_all(
            '/@([\p{L}][\p{L}\p{N}\'\-]*(?:\s+[\p{L}][\p{L}\p{N}\'\-]*){0,'.($maxWords - 1).'})/u',
            $body,
            $matches,
        );

        $occurrences = [];

        foreach ($matches[1] as $match) {
            $words = preg_split('/\s+/u', trim($match)) ?: [];
            $prefixes = [];

            for ($take = 1; $take <= count($words); $take++) {
                $prefixes[] = mb_strtolower(implode(' ', array_slice($words, 0, $take)));
            }

            if ($prefixes !== []) {
                $occurrences[] = $prefixes;
            }
        }

        return $occurrences;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Service;

use App\Modules\Platform\Domain\Contract\RealtimePublisher;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Infrastructure\Realtime\Channel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Centralized notification delivery (docs/03 §5).
 *
 * The hard problem here is not delivery, it is RESTRAINT. A system that
 * notifies on everything gets muted, and a muted system means missed approvals
 * — the notification layer has then broken the workflow it exists to serve
 * (docs/12 §10). Three rules follow from that, and all three are enforced here
 * rather than left to each caller:
 *
 *   1. Never notify someone about their own action.
 *   2. Never notify the same person twice for the same cause (dedupe key).
 *   3. Respect the recipient's preference for the type, including "off".
 */
final class NotificationDispatcher
{
    /**
     * Types that ignore the preference and always deliver in-app.
     *
     * Deliberately short. Being asked to make a decision, and being told your
     * work was bounced back, are not optional: muting them would let someone
     * silently become the bottleneck for their whole team.
     */
    private const ALWAYS_IN_APP = [
        'approval.requested',
        'approval.changes_requested',
        'work.assigned',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly RealtimePublisher $realtime,
    ) {}

    /**
     * @param  list<string>  $recipients  membership ids
     * @param  array<string, mixed>  $payload
     * @return int how many were actually written
     */
    public function dispatch(
        string $type,
        string $subjectType,
        string $subjectId,
        array $recipients,
        array $payload = [],
        ?string $actorMembershipId = null,
        ?string $dedupeSeed = null,
    ): int {
        $actor = $actorMembershipId
            ?? ($this->tenant->hasTenant() && $this->tenant->hasMembership()
                ? $this->tenant->membershipId()
                : null);

        $enriched = $this->enrich($payload, $subjectType, $subjectId, $actor);
        $delivered = 0;

        /** @var list<string> $notified */
        $notified = [];

        foreach (array_unique($recipients) as $membershipId) {
            // Rule 1. Telling someone what they just did is the single fastest
            // way to train a person to ignore the badge.
            if ($actor !== null && (string) $membershipId === $actor) {
                continue;
            }

            if (! $this->wantsInApp($membershipId, $type)) {
                continue;
            }

            $dedupeKey = $this->dedupeKey($type, $subjectId, $dedupeSeed);

            // Rule 2, at the database rather than in code: a partial unique
            // index on (membership_id, dedupe_key) makes redelivery a no-op.
            // insertOrIgnore rather than a read-then-write, which would race.
            $inserted = DB::table('notifications')->insertOrIgnore([
                'id' => (string) new UuidV7,
                'organization_id' => $this->tenant->organizationId(),
                'membership_id' => $membershipId,
                'type' => $type,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'actor_membership_id' => $actor,
                'payload' => json_encode($enriched, JSON_THROW_ON_ERROR),
                'dedupe_key' => $dedupeKey,
                'created_at' => now(),
            ]);

            if ($inserted === 1) {
                $notified[] = (string) $membershipId;
            }

            $delivered += $inserted;
        }

        $this->announce($notified, $type);

        return $delivered;
    }

    /**
     * Tell whoever is looking that their badge changed.
     *
     * Only for rows that were actually written: rule 2 makes redelivery a
     * no-op, and a socket message for a notification that was deduped away
     * would put a number on a badge that the API does not agree with.
     *
     * The channel is keyed by USER, not membership (docs/07 §8), so the ids are
     * translated in one query rather than one per recipient. Nothing about the
     * notification travels — the client refetches, because the count and the
     * list must come from the same place or they will disagree.
     *
     * @param  list<string>  $membershipIds
     */
    private function announce(array $membershipIds, string $type): void
    {
        if ($membershipIds === []) {
            return;
        }

        $organizationId = $this->tenant->organizationId();

        $userIds = DB::table('memberships')
            ->whereIn('id', $membershipIds)
            ->where('organization_id', $organizationId)
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $this->realtime->publish(
                Channel::user($organizationId, (string) $userId),
                'notification.created',
                ['type' => $type],
            );
        }
    }

    /**
     * Resolve audience names to memberships.
     *
     * Rules name recipients by ROLE relative to the subject, never by id: a
     * rule that hardcodes a person breaks the day they change teams
     * (see NotifyAction).
     *
     * @param  list<string>  $audience
     * @return list<string>
     */
    public function resolveAudience(array $audience, string $subjectType, string $subjectId): array
    {
        if ($subjectType !== 'work_item') {
            return [];
        }

        $recipients = [];

        foreach ($audience as $name) {
            $recipients = [...$recipients, ...match ($name) {
                'assignee' => $this->assignmentRole($subjectId, 'assignee'),
                'reviewer' => $this->assignmentRole($subjectId, 'reviewer'),
                'approver' => $this->assignmentRole($subjectId, 'approver'),
                'watchers' => $this->watchers($subjectId),
                'creator' => $this->creator($subjectId),
                'project_owner' => $this->projectOwner($subjectId),
                default => str_starts_with($name, 'membership:')
                    ? [substr($name, 11)]
                    : [],
            }];
        }

        return array_values(array_unique($recipients));
    }

    /** @return array<string, int> */
    public function counts(string $membershipId): array
    {
        $row = DB::table('notifications')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('membership_id', $membershipId)
            ->whereNull('archived_at')
            ->selectRaw('count(*) FILTER (WHERE read_at IS NULL) AS unread, count(*) AS total')
            ->first();

        return [
            'unread' => (int) ($row->unread ?? 0),
            'total' => (int) ($row->total ?? 0),
        ];
    }

    /**
     * Does this person want this type in-app?
     *
     * The absence of a preference row means "the default for this type", so a
     * new notification type never requires backfilling a row for every member
     * of every organization.
     */
    private function wantsInApp(string $membershipId, string $type): bool
    {
        if (in_array($type, self::ALWAYS_IN_APP, strict: true)) {
            return true;
        }

        $preference = DB::table('notification_preferences')
            ->where('membership_id', $membershipId)
            ->where('type', $type)
            ->value('in_app');

        return $preference === null ? true : (bool) $preference;
    }

    /**
     * The natural key that makes delivery idempotent.
     *
     * Seeded with the causation id where one exists, so the same rule firing
     * twice for the same change collides; without one, the subject and type
     * plus the minute is close enough to catch a redelivery without
     * suppressing a genuine second event an hour later.
     */
    private function dedupeKey(string $type, string $subjectId, ?string $seed): string
    {
        return mb_substr(
            $seed !== null
                ? "{$type}:{$subjectId}:{$seed}"
                : "{$type}:{$subjectId}:".now()->format('YmdHi'),
            0,
            200,
        );
    }

    /**
     * A rendered-safe snapshot, so the inbox renders without N joins and still
     * reads correctly after the subject is renamed (docs/03 §5).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrich(array $payload, string $subjectType, string $subjectId, ?string $actor): array
    {
        if ($subjectType === 'work_item' && ! isset($payload['reference'])) {
            $item = DB::table('work_items')
                ->where('id', $subjectId)
                ->first(['reference', 'title']);

            $payload['reference'] = $item?->reference;
            $payload['title'] = $item?->title;
        }

        if ($actor !== null && ! isset($payload['actor_name'])) {
            $payload['actor_name'] = DB::table('memberships')
                ->join('users', 'users.id', '=', 'memberships.user_id')
                ->where('memberships.id', $actor)
                ->value('users.name');
        }

        return $payload;
    }

    /** @return list<string> */
    private function assignmentRole(string $workItemId, string $role): array
    {
        return array_values(
            DB::table('work_item_assignments')
                ->where('work_item_id', $workItemId)
                ->where('role', $role)
                ->whereNull('unassigned_at')
                ->pluck('membership_id')
                ->map(strval(...))
                ->all()
        );
    }

    /** @return list<string> */
    private function watchers(string $workItemId): array
    {
        return array_values(
            DB::table('work_item_watchers')
                ->where('work_item_id', $workItemId)
                ->pluck('membership_id')
                ->map(strval(...))
                ->all()
        );
    }

    /** @return list<string> */
    private function creator(string $workItemId): array
    {
        $creator = DB::table('work_items')
            ->where('id', $workItemId)
            ->value('created_by_membership_id');

        return $creator === null ? [] : [(string) $creator];
    }

    /** @return list<string> */
    private function projectOwner(string $workItemId): array
    {
        $owner = DB::table('work_items')
            ->join('projects', 'projects.id', '=', 'work_items.project_id')
            ->where('work_items.id', $workItemId)
            ->value('projects.owner_membership_id');

        return $owner === null ? [] : [(string) $owner];
    }
}

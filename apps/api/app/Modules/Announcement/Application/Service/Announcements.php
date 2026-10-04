<?php

declare(strict_types=1);

namespace App\Modules\Announcement\Application\Service;

use App\Modules\Announcement\Domain\Exception\AnnouncementRefused;
use App\Modules\Notification\Application\Service\NotificationDispatcher;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\Uid\UuidV7;

/**
 * Saying something to a group, and reading what was said to yours (ADR 0061).
 *
 * @phpstan-type Announcement array{
 *     id: string,
 *     title: string,
 *     body: string,
 *     pinned: bool,
 *     requires_acknowledgement: bool,
 *     audience: array{type: string, id: string|null, name: string|null},
 *     author: array{membership_id: string, name: string|null},
 *     published_at: string,
 *     expires_at: string|null,
 *     expired: bool,
 *     read: bool,
 *     acknowledged: bool,
 *     can_manage: bool
 * }
 */
final class Announcements
{
    /** The feed is a noticeboard, not an archive. */
    public const FEED_LIMIT = 50;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AnnouncementGroups $groups,
        private readonly AnnouncementAuthority $authority,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * What has been said to this person's groups and has not expired.
     * Pinned first, then newest.
     *
     * @return list<Announcement>
     */
    public function feed(): array
    {
        $rows = $this->base()
            ->where(fn (Builder $where): Builder => $this->addressedToMe($where))
            ->where(fn (Builder $where): Builder => $where
                ->whereNull('a.expires_at')
                ->orWhere('a.expires_at', '>', now()))
            ->orderByDesc('a.pinned')
            ->orderByDesc('a.published_at')
            ->limit(self::FEED_LIMIT)
            ->get();

        return array_values($rows->map(fn (stdClass $row): array => $this->present($row))->all());
    }

    /**
     * What this person may change: their own, or everything if they speak for
     * everyone. Expired ones included — this is where they are found again.
     *
     * @return list<array<string, mixed>>
     */
    public function managed(): array
    {
        $query = $this->base()->orderByDesc('a.published_at')->limit(100);

        if (! $this->authority->speaksForEveryone()) {
            $query->where('a.author_membership_id', $this->tenant->membershipId());
        }

        return array_values($query->get()
            ->map(fn (stdClass $row): array => [...$this->present($row), 'stats' => $this->stats($row)])
            ->all());
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        $row = $this->find($id);
        $announcement = $this->present($row);

        $mine = $this->isAddressedToMe($row);

        if (! $mine && ! $announcement['can_manage']) {
            throw new ModelNotFoundException;
        }

        if ($announcement['can_manage']) {
            $announcement['stats'] = $this->stats($row);
            $announcement['not_acknowledged'] = (bool) $row->requires_acknowledgement
                ? $this->notAcknowledged($row)
                : [];
        }

        return $announcement;
    }

    /**
     * @param  array{title: string, body: string, audience_type: string, audience_id?: string|null, pinned?: bool, requires_acknowledgement?: bool, expires_at?: string|null}  $input
     */
    public function publish(array $input): string
    {
        $type = $input['audience_type'];
        $audienceId = $type === 'organization' ? null : ($input['audience_id'] ?? null);

        if (! $this->authority->mayAddress($type, $audienceId)) {
            throw new AuthorizationException('You cannot publish an announcement to this group.');
        }

        $id = (string) new UuidV7;
        $now = CarbonImmutable::now();
        $title = trim($input['title']);

        DB::table('announcements')->insert([
            'id' => $id,
            'organization_id' => $this->tenant->organizationId(),
            'author_membership_id' => $this->tenant->membershipId(),
            'audience_type' => $type,
            'audience_id' => $audienceId,
            'title' => $title,
            'body' => trim($input['body']),
            'pinned' => (bool) ($input['pinned'] ?? false),
            'requires_acknowledgement' => (bool) ($input['requires_acknowledgement'] ?? false),
            'published_at' => $now,
            'expires_at' => $this->expiry($input['expires_at'] ?? null, $now),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The author has read what they wrote; counting them as "not yet"
        // would make every announcement look unread by one.
        DB::table('announcement_reads')->insert([
            'organization_id' => $this->tenant->organizationId(),
            'announcement_id' => $id,
            'membership_id' => $this->tenant->membershipId(),
            'read_at' => $now,
            'acknowledged_at' => $now,
        ]);

        // Once, to whoever is in the group now (ADR 0061). Rule 1 of the
        // dispatcher leaves the author out.
        $this->notifications->dispatch(
            type: 'announcement.published',
            subjectType: 'announcement',
            subjectId: $id,
            recipients: $this->groups->members($type, $audienceId),
            payload: ['title' => $title],
            dedupeSeed: $id,
        );

        return $id;
    }

    /**
     * The words and the flags. Never the audience: the people who were told
     * are the people who were told.
     *
     * @param  array{title?: string, body?: string, pinned?: bool, requires_acknowledgement?: bool, expires_at?: string|null}  $input
     */
    public function update(string $id, array $input): void
    {
        $row = $this->find($id);
        $this->mayManage($row);

        $changes = ['updated_at' => now()];

        foreach (['title', 'body'] as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = trim((string) $input[$field]);
            }
        }

        foreach (['pinned', 'requires_acknowledgement'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $changes[$flag] = (bool) $input[$flag];
            }
        }

        if (array_key_exists('expires_at', $input)) {
            $changes['expires_at'] = $this->expiry($input['expires_at'], CarbonImmutable::parse((string) $row->published_at));
        }

        DB::table('announcements')->where('id', $id)->update($changes);
    }

    public function remove(string $id): void
    {
        $row = $this->find($id);
        $this->mayManage($row);

        DB::table('announcements')->where('id', $id)->delete();
    }

    /**
     * Mark these read for this person. Ids that are not addressed to them, or
     * not there, are passed over rather than refused: the list a screen
     * showed a moment ago is allowed to be stale.
     *
     * @param  list<string>  $ids
     */
    public function markRead(array $ids): int
    {
        $marked = 0;

        foreach ($this->base()->whereIn('a.id', $ids)->get() as $row) {
            if (! $this->isAddressedToMe($row)) {
                continue;
            }

            $marked += DB::table('announcement_reads')->insertOrIgnore([
                'organization_id' => $this->tenant->organizationId(),
                'announcement_id' => (string) $row->id,
                'membership_id' => $this->tenant->membershipId(),
                'read_at' => now(),
            ]);
        }

        return $marked;
    }

    public function acknowledge(string $id): void
    {
        $row = $this->find($id);

        if (! $this->isAddressedToMe($row)) {
            throw new ModelNotFoundException;
        }

        if (! (bool) $row->requires_acknowledgement) {
            throw new AnnouncementRefused(
                'This announcement does not ask to be acknowledged.',
                ['refusal' => 'no_acknowledgement'],
            );
        }

        $now = now();

        DB::table('announcement_reads')->upsert(
            [[
                'organization_id' => $this->tenant->organizationId(),
                'announcement_id' => $id,
                'membership_id' => $this->tenant->membershipId(),
                'read_at' => $now,
                'acknowledged_at' => $now,
            ]],
            ['announcement_id', 'membership_id'],
            ['acknowledged_at'],
        );
    }

    /** How many unread announcements are waiting for this person. */
    public function unreadCount(): int
    {
        return $this->base()
            ->where(fn (Builder $where): Builder => $this->addressedToMe($where))
            ->where(fn (Builder $where): Builder => $where
                ->whereNull('a.expires_at')
                ->orWhere('a.expires_at', '>', now()))
            ->whereNull('r.read_at')
            ->count();
    }

    private function base(): Builder
    {
        $me = $this->tenant->membershipId();

        return DB::table('announcements as a')
            ->leftJoin('announcement_reads as r', fn (JoinClause $join): JoinClause => $join
                ->on('r.announcement_id', '=', 'a.id')
                ->where('r.membership_id', '=', $me))
            ->leftJoin('memberships as m', 'm.id', '=', 'a.author_membership_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('a.organization_id', $this->tenant->organizationId())
            ->select([
                'a.*',
                'r.read_at',
                'r.acknowledged_at',
                'u.name as author_name',
            ]);
    }

    private function addressedToMe(Builder $where): Builder
    {
        $mine = $this->groups->memberOf($this->tenant->membershipId());

        return $where
            ->where('a.audience_type', 'organization')
            ->orWhere(fn (Builder $team): Builder => $team
                ->where('a.audience_type', 'team')
                ->whereIn('a.audience_id', $mine['teams']))
            ->orWhere(fn (Builder $department): Builder => $department
                ->where('a.audience_type', 'department')
                ->whereIn('a.audience_id', $mine['departments']))
            // What somebody wrote stays visible to them after they move teams.
            ->orWhere('a.author_membership_id', $this->tenant->membershipId());
    }

    private function isAddressedToMe(stdClass $row): bool
    {
        if ($row->audience_type === 'organization' || (string) $row->author_membership_id === $this->tenant->membershipId()) {
            return true;
        }

        $mine = $this->groups->memberOf($this->tenant->membershipId());

        return in_array((string) $row->audience_id, $row->audience_type === 'team' ? $mine['teams'] : $mine['departments'], true);
    }

    private function find(string $id): stdClass
    {
        $row = $this->base()->where('a.id', $id)->first();

        if (! $row instanceof stdClass) {
            throw new ModelNotFoundException;
        }

        return $row;
    }

    private function mayManage(stdClass $row): void
    {
        if (! $this->authority->mayManage((string) $row->author_membership_id)) {
            throw new AuthorizationException('Only its author, or someone who can publish to everyone, can change this announcement.');
        }
    }

    private function expiry(?string $expiresAt, CarbonImmutable $publishedAt): ?CarbonImmutable
    {
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }

        $expiry = CarbonImmutable::parse($expiresAt);

        if ($expiry->lessThanOrEqualTo($publishedAt)) {
            throw new AnnouncementRefused(
                'An announcement has to expire after it was published.',
                ['refusal' => 'expires_before_published'],
            );
        }

        return $expiry;
    }

    /** @return array{audience: int, read: int, acknowledged: int} */
    private function stats(stdClass $row): array
    {
        $members = $this->groups->members((string) $row->audience_type, $row->audience_id === null ? null : (string) $row->audience_id);

        $counts = DB::table('announcement_reads')
            ->where('announcement_id', $row->id)
            ->whereIn('membership_id', $members)
            ->selectRaw('count(*) AS read, count(acknowledged_at) AS acknowledged')
            ->first();

        return [
            'audience' => count($members),
            'read' => (int) ($counts->read ?? 0),
            'acknowledged' => (int) ($counts->acknowledged ?? 0),
        ];
    }

    /**
     * Who in the group has not acknowledged it yet. Names, on purpose, and
     * only for this: it is what asking for acknowledgement is for (ADR 0061).
     *
     * @return list<array{membership_id: string, name: string|null}>
     */
    private function notAcknowledged(stdClass $row): array
    {
        $members = $this->groups->members((string) $row->audience_type, $row->audience_id === null ? null : (string) $row->audience_id);

        return array_values(DB::table('memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.id', $members)
            ->whereNotExists(fn (Builder $done): Builder => $done
                ->selectRaw('1')
                ->from('announcement_reads as r')
                ->whereColumn('r.membership_id', 'm.id')
                ->where('r.announcement_id', $row->id)
                ->whereNotNull('r.acknowledged_at'))
            ->orderBy('u.name')
            ->get(['m.id', 'u.name'])
            ->map(fn (object $person): array => [
                'membership_id' => (string) $person->id,
                'name' => $person->name === null ? null : (string) $person->name,
            ])
            ->all());
    }

    /** @return Announcement */
    private function present(stdClass $row): array
    {
        $expiresAt = $row->expires_at === null ? null : CarbonImmutable::parse((string) $row->expires_at);
        $audienceId = $row->audience_id === null ? null : (string) $row->audience_id;

        return [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'body' => (string) $row->body,
            'pinned' => (bool) $row->pinned,
            'requires_acknowledgement' => (bool) $row->requires_acknowledgement,
            'audience' => [
                'type' => (string) $row->audience_type,
                'id' => $audienceId,
                'name' => $this->groups->name((string) $row->audience_type, $audienceId),
            ],
            'author' => [
                'membership_id' => (string) $row->author_membership_id,
                'name' => $row->author_name === null ? null : (string) $row->author_name,
            ],
            'published_at' => CarbonImmutable::parse((string) $row->published_at)->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'expired' => $expiresAt !== null && $expiresAt->isPast(),
            'read' => $row->read_at !== null,
            'acknowledged' => $row->acknowledged_at !== null,
            'can_manage' => $this->authority->mayManage((string) $row->author_membership_id),
        ];
    }
}

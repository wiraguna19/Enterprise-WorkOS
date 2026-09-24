<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Service;

use App\Modules\Calendar\Infrastructure\Eloquent\CalendarFeedModel;
use Illuminate\Support\Str;

/**
 * Issuing, revoking and touching a subscription URL (ADR 0046).
 *
 * The token is the credential — a calendar client cannot present a bearer
 * token — so the two halves of issuing are one method here on purpose: the old
 * URL is destroyed in the same call that mints the new one. A caller that could
 * mint without revoking would leave a leaked URL alive, and "regenerate" is
 * what somebody reaches for precisely when one has leaked.
 */
final class CalendarFeedService
{
    /**
     * The plaintext token is returned ONCE and never stored. Only the digest
     * goes to the database, so this value cannot be recovered later — only
     * replaced (docs/06 §1).
     *
     * @return array{feed: CalendarFeedModel, token: string}
     */
    public function issue(string $membershipId): array
    {
        $token = Str::random(48);

        $this->revoke($membershipId);

        $feed = new CalendarFeedModel;
        $feed->forceFill([
            'id' => CalendarFeedModel::newId(),
            'membership_id' => $membershipId,
            'token_hash' => hash('sha256', $token),
        ])->save();

        return ['feed' => $feed, 'token' => $token];
    }

    public function revoke(string $membershipId): void
    {
        CalendarFeedModel::query()
            ->where('membership_id', $membershipId)
            ->delete();
    }

    /**
     * Record that something fetched the feed.
     *
     * Quietly, and deliberately: this runs on an unauthenticated request from a
     * calendar client polling every few minutes, and `updated_at` moving on
     * every poll would make the row look edited by a person who never touched
     * it. What the column answers is "is anything still subscribed", which is
     * the question behind revoking.
     */
    public function touch(CalendarFeedModel $feed): void
    {
        $feed->forceFill(['last_accessed_at' => now()])->saveQuietly();
    }
}

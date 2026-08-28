<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Http\Controller;

use App\Modules\Calendar\Application\Query\CalendarQuery;
use App\Modules\Calendar\Application\Service\IcsWriter;
use App\Modules\Calendar\Infrastructure\Eloquent\CalendarFeedModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Subscription URLs, and the feed they serve.
 *
 * The feed route is the only unauthenticated endpoint in the API besides login,
 * and it is unauthenticated for a reason that cannot be designed away: a
 * calendar client cannot present a bearer token. The URL is therefore the
 * credential, which shapes everything here — the token is long, stored as a
 * digest, shown once, revocable, and serves only what its owner can already see.
 */
final class CalendarFeedController extends ApiController
{
    /** Nine months back, three forward: what a calendar client actually shows. */
    private const WINDOW_BEHIND_DAYS = 270;

    private const WINDOW_AHEAD_DAYS = 90;

    public function __construct(
        private readonly CalendarQuery $calendar,
        private readonly IcsWriter $ics,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Issue a URL, replacing any previous one.
     *
     * Regenerating is how a leaked URL is dealt with, so it must invalidate the
     * old one — which is why there is one row per membership rather than a
     * collection of feeds someone has to audit.
     */
    public function store(): ApiResponse
    {
        $token = Str::random(48);

        CalendarFeedModel::query()
            ->where('membership_id', $this->tenant->membershipId())
            ->delete();

        $feed = new CalendarFeedModel;
        $feed->forceFill([
            'id' => CalendarFeedModel::newId(),
            'membership_id' => $this->tenant->membershipId(),
            'token_hash' => hash('sha256', $token),
        ])->save();

        return $this->created([
            'id' => (string) $feed->getKey(),
            // Shown once. The digest is all that is stored, so this cannot be
            // recovered later — only replaced (docs/06 §1).
            'url' => url("/api/v1/calendar/{$token}.ics"),
            'created_at' => $feed->created_at->toIso8601String(),
        ]);
    }

    public function show(): ApiResponse
    {
        /** @var CalendarFeedModel|null $feed */
        $feed = CalendarFeedModel::query()
            ->where('membership_id', $this->tenant->membershipId())
            ->first();

        return $this->ok($feed === null ? null : [
            'id' => (string) $feed->getKey(),
            'created_at' => $feed->created_at->toIso8601String(),
            // Not the URL — it is not stored. What IS answerable is whether
            // anything is subscribed, which is the question behind revoking.
            'last_accessed_at' => $feed->last_accessed_at?->toIso8601String(),
        ]);
    }

    public function destroy(): ApiResponse
    {
        CalendarFeedModel::query()
            ->where('membership_id', $this->tenant->membershipId())
            ->delete();

        return $this->noContent();
    }

    /**
     * The feed itself. No session, no tenant — the token establishes both.
     */
    public function feed(string $token): Response
    {
        $feed = CalendarFeedModel::forToken($token);

        // 404, not 401: a wrong token is not a login prompt, and distinguishing
        // "no such feed" from "not yours" would confirm a guess (docs/05 §3).
        if ($feed === null) {
            abort(404);
        }

        $feed->forceFill(['last_accessed_at' => now()])->saveQuietly();

        $body = $this->tenant->runForMembership(
            (string) $feed->organization_id,
            (string) $feed->membership_id,
            fn (): string => $this->render(),
        );

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="work.ics"',
            // A feed URL must never end up in a shared cache: it is a
            // credential, and the body is one person's work.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function render(): string
    {
        $now = CarbonImmutable::now();

        $events = $this->calendar->between(
            $now->subDays(self::WINDOW_BEHIND_DAYS),
            $now->addDays(self::WINDOW_AHEAD_DAYS),
            // Projected occurrences are deliberately excluded: a calendar
            // client cannot show "this does not exist yet", and an event that
            // silently disappears when the rule changes is worse than one that
            // appears a little late.
            ['work', 'milestones'],
        );

        return $this->ics->render('Work', array_map(
            static fn (array $event): array => [
                'uid' => $event['type'].'-'.$event['id'].'@enterprise-work-os',
                'summary' => ($event['reference'] === null ? '' : $event['reference'].' · ').$event['title'],
                'starts_at' => CarbonImmutable::parse((string) $event['starts_at']),
                'all_day' => (bool) $event['all_day'],
                'url' => $event['reference'] === null ? '' : url('/work/'.$event['reference']),
            ],
            $events,
        ));
    }
}

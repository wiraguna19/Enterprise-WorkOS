<?php

declare(strict_types=1);

use App\Modules\Calendar\Application\Service\IcsWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The subscription feed, which is the only unauthenticated endpoint in the API
 * besides login — and is unauthenticated for a reason that cannot be designed
 * away: a calendar client cannot present a bearer token.
 */
beforeEach(function (): void {
    $this->token = $this->loginAs('sarah@acme.test');
});

it('issues a URL once and stores only its digest', function (): void {
    $url = $this->withToken($this->token)
        ->postJson('/api/v1/calendar/feed')
        ->assertCreated()
        ->json('data.url');

    expect($url)->toContain('/api/v1/calendar/');

    $token = str_replace('.ics', '', basename((string) $url));
    $stored = DB::table('calendar_feeds')->value('token_hash');

    // A database leak must not yield working feed URLs — the same rule sessions
    // follow (docs/06 §1).
    expect($stored)->toBe(hash('sha256', $token))
        ->and($stored)->not->toBe($token);
});

it('serves that person\'s work as a calendar', function (): void {
    $url = $this->withToken($this->token)
        ->postJson('/api/v1/calendar/feed')
        ->assertCreated()
        ->json('data.url');

    $body = $this->get(parse_url((string) $url, PHP_URL_PATH))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        // A feed URL is a credential; its body must never reach a shared cache.
        // Asserted in Symfony's order, not the controller's: ResponseHeaderBag
        // re-emits cache-control directives sorted, so matching the string the
        // controller passes in would fail for a header that is in fact correct.
        ->assertHeader('Cache-Control', 'no-store, private')
        ->getContent();

    expect($body)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->and($body)->toContain('BEGIN:VEVENT')
        ->and($body)->toEndWith("END:VCALENDAR\r\n");
});

it('answers 404 for a token that is not a feed', function (): void {
    // Not 401: a wrong token is not a login prompt, and distinguishing "no such
    // feed" from "not yours" would confirm a guess (docs/05 §3).
    $this->get('/api/v1/calendar/'.str_repeat('a', 48).'.ics')->assertNotFound();
});

it('invalidates the old URL when a new one is issued', function (): void {
    $first = $this->withToken($this->token)->postJson('/api/v1/calendar/feed')->json('data.url');
    $this->withToken($this->token)->postJson('/api/v1/calendar/feed')->assertCreated();

    // Regenerating is how a leaked URL is dealt with, so it has to actually
    // revoke the previous one.
    $this->get(parse_url((string) $first, PHP_URL_PATH))->assertNotFound();
});

it('stops serving once revoked', function (): void {
    $url = $this->withToken($this->token)->postJson('/api/v1/calendar/feed')->json('data.url');

    $this->withToken($this->token)->deleteJson('/api/v1/calendar/feed')->assertNoContent();

    $this->get(parse_url((string) $url, PHP_URL_PATH))->assertNotFound();
});

it('reports whether anything is subscribed, without revealing the URL', function (): void {
    $this->withToken($this->token)->postJson('/api/v1/calendar/feed')->assertCreated();

    $feed = $this->withToken($this->token)
        ->getJson('/api/v1/calendar/feed')
        ->assertOk()
        ->json('data');

    expect($feed)->not->toBeNull()
        ->and($feed)->not->toHaveKey('url')
        ->and($feed['last_accessed_at'])->toBeNull();
});

it('shows only what the subscriber can already see', function (): void {
    // Budi's feed must not carry Sarah's private-project work, and a feed is
    // the easiest place to forget that the visibility rule still applies
    // (docs/06 §2).
    $url = $this->withToken($this->loginAs('tono@acme.test'))
        ->postJson('/api/v1/calendar/feed')
        ->assertCreated()
        ->json('data.url');

    $body = $this->get(parse_url((string) $url, PHP_URL_PATH))->assertOk()->getContent();

    // Tono is assigned nothing and is in no project.
    expect($body)->not->toContain('ENG-');
});

/** ── the writer's two rules, which is where naive ICS output breaks ── */
it('escapes the characters that would truncate an event', function (): void {
    $ics = app(IcsWriter::class)->render('Work', [[
        'uid' => 'x@test',
        'summary' => 'Fix pricing, then deploy; carefully',
        'starts_at' => CarbonImmutable::parse('2026-09-01 09:00:00'),
        'all_day' => false,
    ]]);

    // An unescaped comma ends the property value: Outlook silently shows a
    // truncated event.
    expect($ics)->toContain('SUMMARY:Fix pricing\\, then deploy\\; carefully');
});

it('folds lines longer than 75 octets', function (): void {
    $ics = app(IcsWriter::class)->render('Work', [[
        'uid' => 'x@test',
        'summary' => str_repeat('A very long work item title. ', 8),
        'starts_at' => CarbonImmutable::parse('2026-09-01 09:00:00'),
        'all_day' => false,
    ]]);

    foreach (explode("\r\n", $ics) as $line) {
        // Clients that receive an over-long line drop the event entirely.
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }

    // Folded, not truncated: the continuation carries the rest.
    expect($ics)->toContain("\r\n ");
});

it('keeps an all-day event on its day in every timezone', function (): void {
    $ics = app(IcsWriter::class)->render('Work', [[
        'uid' => 'm@test',
        'summary' => 'Beta cutover',
        'starts_at' => CarbonImmutable::parse('2026-09-01 00:00:00'),
        'all_day' => true,
    ]]);

    // A UTC timestamp would move the milestone a day for half the world.
    expect($ics)->toContain('DTSTART;VALUE=DATE:20260901')
        ->and($ics)->not->toContain('DTSTART:20260901T');
});

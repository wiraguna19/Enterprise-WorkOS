<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;

/**
 * The timesheet: one person's hours, by day (docs/03 §4).
 *
 * The tests that matter are about the axis and the audience. Time is written
 * against a work item and read back by day, so the grouping is the feature —
 * and it is read back for ONE person, so the tests prove that nobody else's
 * hours can be reached through it.
 */
beforeEach(function (): void {
    $this->sarah = $this->loginAs('sarah@acme.test');
    $this->ahmad = $this->loginAs('ahmad@acme.test');
});

/** @param  array<string, mixed>  $payload */
function logTime(string $token, array $payload, string $reference = 'ENG-142'): void
{
    test()->withToken($token)
        ->postJson("/api/v1/work-items/{$reference}/time-entries", $payload)
        ->assertCreated();
}

it('groups a person their own hours by day', function (): void {
    // Immutable on purpose: ->addDay() on a mutable Carbon would move the very
    // variable the assertions below compare against, and the test would pass or
    // fail depending on which line read it last.
    $monday = CarbonImmutable::now()->startOfWeek();

    logTime($this->sarah, ['hours' => 2, 'logged_on' => $monday->toDateString()]);
    logTime($this->sarah, ['hours' => 1.5, 'logged_on' => $monday->toDateString(), 'note' => 'review']);
    logTime($this->sarah, ['hours' => 4, 'logged_on' => $monday->addDay()->toDateString()]);

    $response = $this->withToken($this->sarah)->getJson('/api/v1/me/time')->assertOk();

    $days = collect($response->json('data'));
    $first = $days->firstWhere('date', $monday->toDateString());

    expect($days)->toHaveCount(2)
        ->and($first['hours'])->toBe(3.5)
        ->and($first['entries'])->toHaveCount(2)
        // An entry without its work item is not a timesheet line.
        ->and($first['entries'][0]['work_item']['reference'])->toBe('ENG-142')
        ->and($response->json('meta.total_hours'))->toBe(7.5)
        ->and($response->json('meta.days_logged'))->toBe(2);
});

it('defaults to this week', function (): void {
    logTime($this->sarah, ['hours' => 3, 'logged_on' => now()->startOfWeek()->toDateString()]);
    // Last week: real, and deliberately outside the default window.
    logTime($this->sarah, ['hours' => 8, 'logged_on' => now()->startOfWeek()->subDays(3)->toDateString()]);

    $thisWeek = $this->withToken($this->sarah)->getJson('/api/v1/me/time')->assertOk();

    // Cast, because JSON has one number type: a whole total arrives as int 3
    // and a fractional one as float 3.5, and asserting strictly against a
    // literal would make this test depend on how round the answer happens to be.
    expect((float) $thisWeek->json('meta.total_hours'))->toBe(3.0);

    $both = $this->withToken($this->sarah)->getJson(
        '/api/v1/me/time?from='.now()->startOfWeek()->subWeek()->toDateString()
            .'&to='.now()->endOfWeek()->toDateString()
    )->assertOk();

    expect((float) $both->json('meta.total_hours'))->toBe(11.0);
});

it('never returns someone else\'s hours', function (): void {
    logTime($this->sarah, ['hours' => 6, 'logged_on' => now()->startOfWeek()->toDateString()]);

    // Ahmad can see Sarah's work and could even log time in her name; his own
    // timesheet is still only his.
    $mine = $this->withToken($this->ahmad)->getJson('/api/v1/me/time')->assertOk();

    expect($mine->json('data'))->toBe([])
        ->and((float) $mine->json('meta.total_hours'))->toBe(0.0);
});

it('counts time logged on your behalf as yours', function (): void {
    // A manager filling in a timesheet for someone on leave is real (docs/03
    // §4). The hours belong to the person named, not to whoever typed them.
    $this->withToken($this->ahmad)
        ->postJson('/api/v1/work-items/ENG-142/time-entries', [
            'hours' => 5,
            'logged_on' => now()->startOfWeek()->toDateString(),
            'membership_id' => '01900000-0000-7000-8000-000000000203',
        ])->assertCreated();

    expect((float) $this->withToken($this->sarah)->getJson('/api/v1/me/time')->json('meta.total_hours'))
        ->toBe(5.0)
        ->and((float) $this->withToken($this->ahmad)->getJson('/api/v1/me/time')->json('meta.total_hours'))
        ->toBe(0.0);
});

it('refuses to be asked for a decade', function (): void {
    $meta = $this->withToken($this->sarah)
        ->getJson('/api/v1/me/time?from=2020-01-01&to=2030-01-01')
        ->assertOk()
        ->json('meta');

    // Clamped rather than rejected: the caller asked a reasonable question
    // badly, and an empty 422 helps nobody. The window it actually got is in
    // the response, so a client can say so.
    expect($meta['from'])->toBe('2020-01-01')
        ->and($meta['to'])->toBe('2020-04-10');
});

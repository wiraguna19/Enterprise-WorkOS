<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The calendar is a view, never a record (docs/10 Phase 5, docs/06 §2).
 *
 * Which means the tests that matter are about what it must NOT surface: a
 * calendar aggregates every dated thing in the tenant, so it is the second
 * easiest place after search to leak one.
 */
beforeEach(function (): void {
    $this->manager = $this->loginAs('ahmad@acme.test');
});

it('returns work by its due date', function (): void {
    $events = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/calendar?sources=work&from='.now()->subMonths(2)->toDateString()
                .'&to='.now()->addMonths(2)->toDateString())
            ->assertOk()
            ->json('data')
    );

    expect($events)->not->toBeEmpty()
        ->and($events->pluck('type')->unique()->all())->toBe(['work_item'])
        // Deadlines have a time; rendering them all-day loses "Friday 17:00",
        // which is the part people plan around.
        ->and($events->first()['all_day'])->toBeFalse();
});

it('returns milestones as dates, not moments', function (): void {
    DB::table('milestones')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'project_id' => '01900003-0000-7000-8000-000000000001',
        'name' => 'Beta cutover',
        'due_date' => now()->addWeek()->toDateString(),
        'status' => 'open',
    ]);

    $milestone = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/calendar?sources=milestones')
            ->assertOk()
            ->json('data')
    )->firstWhere('title', 'Beta cutover');

    expect($milestone)->not->toBeNull()
        ->and($milestone['all_day'])->toBeTrue();
});

it('projects recurring work that does not exist yet, and says so', function (): void {
    DB::table('recurrences')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'created_by_membership_id' => '01900000-0000-7000-8000-000000000202',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
        'template' => json_encode(['title' => 'Monday standup notes', 'type' => 'task'], JSON_THROW_ON_ERROR),
        'next_run_at' => now()->addDay(),
        'created_at' => now()->subMonth(),
        'updated_at' => now(),
    ]);

    $projected = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/calendar?sources=recurring')
            ->assertOk()
            ->json('data')
    )->firstWhere('title', 'Monday standup notes');

    // "This will appear on Monday" and "this exists and is due Monday" are
    // different facts, and a calendar that blurs them teaches people to
    // distrust it.
    expect($projected)->not->toBeNull()
        ->and($projected['is_projected'])->toBeTrue();
});

it('never shows a milestone from a project the person cannot open', function (): void {
    DB::table('milestones')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        // FIN is private and Budi is not a member.
        'project_id' => '01900003-0000-7000-8000-000000000005',
        'name' => 'Confidential budget lock',
        'due_date' => now()->addDays(3)->toDateString(),
        'status' => 'open',
    ]);

    $titles = collect(
        $this->withToken($this->loginAs('budi@acme.test'))
            ->getJson('/api/v1/calendar?sources=milestones')
            ->assertOk()
            ->json('data')
    )->pluck('title');

    expect($titles)->not->toContain('Confidential budget lock');
});

it('never shows another tenant\'s work', function (): void {
    $references = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/calendar?from='.now()->subYear()->toDateString()
                .'&to='.now()->addYear()->toDateString())
            ->assertOk()
            ->json('data')
    )->pluck('reference');

    expect($references)->not->toContain('GBX-1');
});

it('refuses an unknown source instead of ignoring it', function (): void {
    $this->withToken($this->manager)
        ->getJson('/api/v1/calendar?sources=work,astrology')
        ->assertStatus(422);
});

it('bounds a window the caller asked to be enormous', function (): void {
    $meta = $this->withToken($this->manager)
        ->getJson('/api/v1/calendar?from=2020-01-01&to=2030-01-01')
        ->assertOk()
        ->json('meta');

    // A daily rule over ten years is thousands of projected events nobody
    // reads, and one query nobody wants to pay for.
    expect(now()->parse($meta['from'])->diffInDays(now()->parse($meta['to'])))
        ->toBeLessThanOrEqual(186);
});

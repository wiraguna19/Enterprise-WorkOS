<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Application\Service\RecurrenceMaterializer;
use App\Modules\Workflow\Infrastructure\Eloquent\RecurrenceModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Recurring work (docs/03 §4, docs/10 Phase 5).
 *
 * The tests that matter are about restraint: creating the occurrence is easy,
 * and the ways this goes wrong in production are creating it twice, creating
 * thirty of them after an outage, or one broken rule taking the tick down.
 */
beforeEach(function (): void {
    $this->acme = '01900000-0000-7000-8000-0000000000ac';
    $this->materializer = app(RecurrenceMaterializer::class);

    app(TenantContext::class)->setFromSession(
        $this->acme,
        '01900000-0000-7000-8000-000000000202',
        '01900000-0000-7000-8000-000000000002',
    );
});

/** @param array<string, mixed> $overrides */
function makeRecurrence(array $overrides = []): string
{
    $id = (string) new UuidV7;

    DB::table('recurrences')->insert([
        'id' => $id,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'created_by_membership_id' => '01900000-0000-7000-8000-000000000202',
        'rrule' => 'FREQ=DAILY;INTERVAL=1',
        'template' => json_encode([
            'title' => 'Check the deployment dashboard',
            'type' => 'task',
            'project_id' => '01900003-0000-7000-8000-000000000001',
        ], JSON_THROW_ON_ERROR),
        'next_run_at' => now()->subMinute(),
        'created_at' => now()->subMonth(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

it('creates the work a due rule is for', function (): void {
    $id = makeRecurrence();

    $tally = $this->materializer->run();

    expect($tally['created'])->toBe(1);

    $item = DB::table('work_items')->where('recurrence_id', $id)->first();

    expect($item)->not->toBeNull()
        ->and($item->title)->toBe('Check the deployment dashboard')
        // The link back is what tells a reader why an item nobody remembers
        // requesting exists.
        ->and($item->recurrence_id)->toBe($id);
});

it('advances the rule instead of firing it again', function (): void {
    makeRecurrence();

    $this->materializer->run();
    $second = $this->materializer->run();

    expect($second['created'])->toBe(0)
        ->and(DB::table('work_items')->whereNotNull('recurrence_id')->count())->toBe(1);
});

it('creates one occurrence after an outage, not the whole backlog', function (): void {
    // A daily rule that has not run for a month. Waking up to thirty copies of
    // a checklist is worse than waking up to one and a log line.
    makeRecurrence(['next_run_at' => now()->subDays(30)]);

    $tally = $this->materializer->run();

    expect($tally['created'])->toBe(1)
        ->and(DB::table('work_items')->whereNotNull('recurrence_id')->count())->toBe(1);
});

it('schedules the next run in the future, never in the past', function (): void {
    $id = makeRecurrence(['next_run_at' => now()->subDays(30)]);

    $this->materializer->run();

    $recurrence = RecurrenceModel::query()->findOrFail($id);

    // Without this the rule is due again the instant the next tick runs, and
    // the "one occurrence" guarantee above quietly becomes one per tick.
    expect($recurrence->next_run_at->greaterThan(CarbonImmutable::now()))->toBeTrue()
        ->and($recurrence->last_run_at)->not->toBeNull();
});

it('leaves a rule that is not due yet alone', function (): void {
    makeRecurrence(['next_run_at' => now()->addHour()]);

    expect($this->materializer->run()['created'])->toBe(0);
});

it('stops a rule that has reached its end date', function (): void {
    $id = makeRecurrence([
        'next_run_at' => now()->subMinute(),
        'ends_at' => now()->subDay(),
    ]);

    $this->materializer->run();

    $recurrence = RecurrenceModel::query()->findOrFail($id);

    expect($recurrence->is_active)->toBeFalse()
        ->and(DB::table('work_items')->where('recurrence_id', $id)->count())->toBe(0);
});

it('stops a finite rule once its occurrences run out', function (): void {
    // COUNT=1 with the count already spent: there is no next occurrence, and a
    // rule with no future is switched off rather than retried every tick.
    $id = makeRecurrence([
        'rrule' => 'FREQ=DAILY;COUNT=1',
        'created_at' => now()->subYear(),
    ]);

    $this->materializer->run();

    expect(RecurrenceModel::query()->findOrFail($id)->is_active)->toBeFalse();
});

it('takes a malformed rule out of service rather than failing every tick', function (): void {
    $broken = makeRecurrence(['rrule' => 'FREQ=NONSENSE;INTERVAL=oops']);
    $healthy = makeRecurrence();

    $tally = $this->materializer->run();

    // One bad rule must not stop the others — the same posture the rule engine
    // takes with a failing rule (docs/02 §7).
    expect($tally['failed'])->toBe(1)
        ->and($tally['created'])->toBe(1)
        ->and(RecurrenceModel::query()->findOrFail($broken)->is_active)->toBeFalse()
        ->and(RecurrenceModel::query()->findOrFail($healthy)->is_active)->toBeTrue();
});

it('gives the created work a deadline relative to its occurrence', function (): void {
    $id = makeRecurrence([
        'template' => json_encode([
            'title' => 'Weekly report',
            'type' => 'task',
            'project_id' => '01900003-0000-7000-8000-000000000001',
            'due_in_days' => 3,
        ], JSON_THROW_ON_ERROR),
    ]);

    $this->materializer->run();

    $item = DB::table('work_items')->where('recurrence_id', $id)->first();

    // An absolute due date in a template would be the same date forever.
    expect(CarbonImmutable::parse($item->due_at)->toDateString())
        ->toBe(CarbonImmutable::now()->addDays(3)->toDateString());
});

it('creates work inside the tenant that owns the rule', function (): void {
    // Every tenant-owned column has to move together: the creator is bound to
    // the organization by a composite foreign key, so a Globex rule created by
    // an Acme membership is refused by the database — which is the constraint
    // doing its job, not a test detail (docs/03 §0).
    $globex = makeRecurrence([
        'organization_id' => '01900000-0000-7000-8000-0000000000b0',
        'created_by_membership_id' => '01900000-0000-7000-8000-000000000301',
        'template' => json_encode([
            'title' => 'Globex weekly',
            'type' => 'task',
        ], JSON_THROW_ON_ERROR),
    ]);

    $this->materializer->run();

    $item = DB::table('work_items')->where('recurrence_id', $globex)->first();

    // The scheduler belongs to no organization; each occurrence is created
    // inside runFor(), so the tenant column comes from the rule and not from
    // whoever happened to be bound when the tick started (docs/01 §6).
    expect($item)->not->toBeNull()
        ->and($item->organization_id)->toBe('01900000-0000-7000-8000-0000000000b0');
});

it('creates a recurrence through the API and schedules its first run', function (): void {
    $token = $this->loginAs('ahmad@acme.test');

    $created = $this->withToken($token)->postJson('/api/v1/recurrences', [
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
        'template' => [
            'title' => 'Weekly platform review',
            'type' => 'task',
            'project_id' => '01900003-0000-7000-8000-000000000001',
            'due_in_days' => 2,
        ],
    ])->assertCreated()->json('data');

    expect($created['is_active'])->toBeTrue()
        ->and($created['created_count'])->toBe(0)
        ->and(CarbonImmutable::parse($created['next_run_at'])->greaterThan(CarbonImmutable::now()))
        ->toBeTrue();
});

it('refuses a rule it cannot read, at the door', function (): void {
    // Storing it would mean the rule fails for the first time on the scheduler
    // at 03:00, where whoever wrote it is not watching.
    $this->withToken($this->loginAs('ahmad@acme.test'))
        ->postJson('/api/v1/recurrences', [
            'rrule' => 'EVERY OTHER TUESDAY PLEASE',
            'template' => ['title' => 'Nope'],
        ])->assertStatus(422)
        ->assertJsonPath(
            'error.details.rrule.0',
            fn (string $message): bool => str_contains($message, 'this system can read'),
        );
});

it('refuses a rule with no future', function (): void {
    $this->withToken($this->loginAs('ahmad@acme.test'))
        ->postJson('/api/v1/recurrences', [
            'rrule' => 'FREQ=DAILY;COUNT=1',
            'starts_at' => now()->subYear()->toIso8601String(),
            'template' => ['title' => 'Already over'],
        ])->assertStatus(422);
});

it('stops a recurrence without erasing what it made', function (): void {
    $id = makeRecurrence();

    $this->materializer->run();

    $this->withToken($this->loginAs('ahmad@acme.test'))
        ->deleteJson("/api/v1/recurrences/{$id}")
        ->assertNoContent();

    // The work it already created still points back here — that link is how
    // anyone answers "where did this come from" months later.
    expect(RecurrenceModel::query()->findOrFail($id)->is_active)->toBeFalse()
        ->and(DB::table('work_items')->where('recurrence_id', $id)->count())->toBe(1);

    expect($this->materializer->run()['created'])->toBe(0);
});

it('never lists another tenant\'s recurrences', function (): void {
    makeRecurrence([
        'organization_id' => '01900000-0000-7000-8000-0000000000b0',
        'created_by_membership_id' => '01900000-0000-7000-8000-000000000301',
    ]);
    $mine = makeRecurrence();

    $ids = collect(
        $this->withToken($this->loginAs('ahmad@acme.test'))
            ->getJson('/api/v1/recurrences')
            ->assertOk()
            ->json('data')
    )->pluck('id');

    expect($ids)->toContain($mine)->toHaveCount(1);
});

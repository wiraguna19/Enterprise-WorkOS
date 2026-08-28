<?php

declare(strict_types=1);

use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\DB;

/**
 * Time logging, and the rollup that has to stay honest (docs/03 §4, ADR 0003).
 *
 * The interesting tests here are the ones about the number: a derived value
 * that can drift is worse than no derived value, because every report built on
 * it inherits the drift silently.
 */
beforeEach(function (): void {
    $this->employee = $this->loginAs('sarah@acme.test');
    $this->manager = $this->loginAs('ahmad@acme.test');

    $this->sarah = '01900000-0000-7000-8000-000000000203';
    $this->reference = 'ENG-142';
    $this->itemId = '01900014-0000-7000-8000-000000000001';
});

it('logs time and rolls it into the work item immediately', function (): void {
    $this->withToken($this->employee)
        ->postJson("/api/v1/work-items/{$this->reference}/time-entries", [
            'hours' => 3.5,
            'logged_on' => now()->subDay()->toDateString(),
            'note' => 'Traced the pool exhaustion',
        ])->assertCreated()
        ->assertJsonPath('data.hours', 3.5);

    // ADR 0003: the total is correct in the same transaction, not eventually.
    $item = WorkItemModel::query()->findOrFail($this->itemId);

    expect((float) $item->actual_hours_cache)->toBe(3.5);
});

it('sums entries rather than adding deltas', function (): void {
    foreach ([2, 1.25, 0.5] as $hours) {
        $this->withToken($this->employee)
            ->postJson("/api/v1/work-items/{$this->reference}/time-entries", ['hours' => $hours])
            ->assertCreated();
    }

    $item = WorkItemModel::query()->findOrFail($this->itemId);

    expect((float) $item->actual_hours_cache)->toBe(3.75);
});

it('returns the rollup beside the rows it came from', function (): void {
    $this->withToken($this->employee)
        ->postJson("/api/v1/work-items/{$this->reference}/time-entries", ['hours' => 2])
        ->assertCreated();

    $response = $this->withToken($this->employee)
        ->getJson("/api/v1/work-items/{$this->reference}/time-entries")
        ->assertOk();

    // A number that looks wrong can be checked on the spot rather than reported
    // as a mystery — the two must agree, and the endpoint says both.
    // Cast on the way in: JSON has one number type, so 2.0 arrives as 2 and a
    // strict comparison would be asserting something about the encoder rather
    // than about the rollup.
    expect((float) $response->json('meta.total_hours'))->toBe(2.0)
        ->and((float) $response->json('meta.cached_total'))->toBe(2.0);
});

it('puts the total back when an entry is removed', function (): void {
    $entryId = $this->withToken($this->employee)
        ->postJson("/api/v1/work-items/{$this->reference}/time-entries", ['hours' => 4])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($this->employee)
        ->deleteJson("/api/v1/work-items/{$this->reference}/time-entries/{$entryId}")
        ->assertNoContent();

    $item = WorkItemModel::query()->findOrFail($this->itemId);

    expect((float) $item->actual_hours_cache)->toBe(0.0);
});

it('refuses more hours than a day has, across entries', function (): void {
    $today = now()->toDateString();

    $this->withToken($this->employee)
        ->postJson("/api/v1/work-items/{$this->reference}/time-entries", [
            'hours' => 20, 'logged_on' => $today,
        ])->assertCreated();

    // The single-entry CHECK would allow this one; the day would not.
    $this->withToken($this->employee)
        ->postJson("/api/v1/work-items/{$this->reference}/time-entries", [
            'hours' => 6, 'logged_on' => $today,
        ])->assertStatus(422)
        ->assertJsonPath('error.code', 'time_entry.invalid');
});

it('counts the whole day, not just this work item', function (): void {
    $today = now()->toDateString();

    $this->withToken($this->employee)
        ->postJson("/api/v1/work-items/{$this->reference}/time-entries", [
            'hours' => 20, 'logged_on' => $today,
        ])->assertCreated();

    // A person cannot spend more than a day in a day, however many items they
    // spread it across (docs/02 §11).
    $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-143/time-entries', [
            'hours' => 6, 'logged_on' => $today,
        ])->assertStatus(422);
});

it('refuses time logged in the future', function (): void {
    $this->withToken($this->employee)
        ->postJson("/api/v1/work-items/{$this->reference}/time-entries", [
            'hours' => 1, 'logged_on' => now()->addDay()->toDateString(),
        ])->assertStatus(422);
});

it('refuses zero and negative hours', function (): void {
    foreach ([0, -2] as $hours) {
        $this->withToken($this->employee)
            ->postJson("/api/v1/work-items/{$this->reference}/time-entries", ['hours' => $hours])
            ->assertStatus(422);
    }
});

it('lets a manager log time for someone else, and records whose it is', function (): void {
    $entry = $this->withToken($this->manager)
        ->postJson("/api/v1/work-items/{$this->reference}/time-entries", [
            'hours' => 2,
            'membership_id' => $this->sarah,
        ])->assertCreated()->json('data');

    expect($entry['membership_id'])->toBe($this->sarah)
        ->and($entry['person'])->toBe('Sarah Chen');
});

it('refuses to let an employee log time in someone else\'s name', function (): void {
    // Sarah holds work_item.log_time but not work_item.assign: she may account
    // for her own hours, not put hours on someone else's capacity report.
    $this->withToken($this->employee)
        ->postJson("/api/v1/work-items/{$this->reference}/time-entries", [
            'hours' => 1,
            'membership_id' => '01900000-0000-7000-8000-000000000204',
        ])->assertStatus(403);
});

it('hides someone else\'s entry rather than refusing it', function (): void {
    $entryId = $this->withToken($this->manager)
        ->postJson("/api/v1/work-items/{$this->reference}/time-entries", [
            'hours' => 1, 'membership_id' => $this->sarah,
        ])->assertCreated()->json('data.id');

    // Budi may log time, but this entry is not his. A 403 would confirm it
    // exists; whose it is is not information this endpoint owes him.
    $this->withToken($this->loginAs('budi@acme.test'))
        ->deleteJson("/api/v1/work-items/{$this->reference}/time-entries/{$entryId}")
        ->assertNotFound();
});

it('will not log time against work the person cannot see', function (): void {
    // Tono is assigned nothing and is in no project: ENG-142 does not exist as
    // far as he is concerned, and logging must agree with reading (docs/06 §2).
    $this->withToken($this->loginAs('tono@acme.test'))
        ->postJson("/api/v1/work-items/{$this->reference}/time-entries", ['hours' => 1])
        ->assertStatus(403);
});

it('never lets an entry cross tenants', function (): void {
    $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/GBX-1/time-entries', ['hours' => 1])
        ->assertNotFound();

    expect(DB::table('time_entries')->count())->toBe(0);
});

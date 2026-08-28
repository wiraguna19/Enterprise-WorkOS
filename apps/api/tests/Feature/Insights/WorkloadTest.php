<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The workload definition, asserted line by line against docs/02 §11.
 *
 * This number is what a manager makes staffing decisions from, which is why the
 * document defines it precisely and why these tests read like the formula. A
 * workload test that only checked "returns a number" would pass through every
 * way of getting it wrong.
 */
const ACME_ORG = '01900000-0000-7000-8000-0000000000ac';
const WORKFLOW = '01900001-0000-7000-8000-000000000001';
const TODO_STATE = '01900002-0000-7000-8000-000000000002';
const DONE_STATE = '01900002-0000-7000-8000-000000000006';
const BUDI_PART_TIME = '01900000-0000-7000-8000-000000000206';
const SARAH_M = '01900000-0000-7000-8000-000000000203';

beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');
    $this->monday = now()->startOfWeek()->toDateString();
});

/** @param array<string, mixed> $attributes */
function assignWork(string $membershipId, array $attributes = []): string
{
    $id = (string) new UuidV7;

    DB::table('work_items')->insert([
        'id' => $id,
        'organization_id' => ACME_ORG,
        'reference' => 'ENG-'.random_int(8000, 8999),
        'title' => 'Workload fixture',
        'workflow_id' => WORKFLOW,
        'workflow_state_id' => $attributes['workflow_state_id'] ?? TODO_STATE,
        'state_category' => $attributes['state_category'] ?? 'todo',
        'estimate_hours' => $attributes['estimate_hours'] ?? null,
        'start_date' => $attributes['start_date'] ?? null,
        'due_at' => $attributes['due_at'] ?? null,
        // ck_work_items_completed: a done item must say when. The constraint is
        // right and the fixture has to satisfy it like any other write.
        'completed_at' => ($attributes['state_category'] ?? 'todo') === 'done' ? now() : null,
        'created_by_membership_id' => $membershipId,
    ]);

    DB::table('work_item_assignments')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => ACME_ORG,
        'work_item_id' => $id,
        'membership_id' => $membershipId,
        'role' => 'assignee',
        'assigned_at' => now(),
    ]);

    return $id;
}

function workloadFor(string $token, string $membershipId, string $week): array
{
    return test()->withToken($token)
        ->getJson("/api/v1/people/{$membershipId}/workload?week={$week}")
        ->assertOk()
        ->json('data');
}

/**
 * The seed gives people real work, which is the point of it — so these tests
 * measure what ADDING an item does rather than asserting a total. A test that
 * demanded an empty week would be asserting against a fixture rather than
 * against the formula, and would break every time the seed grew.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function workloadAround(string $token, string $membershipId, string $week, callable $change): array
{
    $before = workloadFor($token, $membershipId, $week);
    $change();

    return [$before, workloadFor($token, $membershipId, $week)];
}

it('reads capacity from the person, not from an assumed 40', function (): void {
    // Budi is part-time at 24 h. A workload denominator that silently defaults
    // to 40 is how a manager over-commits a part-time colleague.
    $load = workloadFor($this->admin, BUDI_PART_TIME, $this->monday);

    expect((float) $load['capacity_hours'])->toBe(24.0)
        // Null rather than 0: nothing in the schema records leave, and zero
        // would be a claim that there is none.
        ->and($load['time_off_hours'])->toBeNull();
});

it('spreads an estimate across the days it spans', function (): void {
    // Ten hours over ten days, of which four fall in this week.
    [$before, $after] = workloadAround($this->admin, SARAH_M, $this->monday, fn () => assignWork(SARAH_M, [
        'estimate_hours' => 10,
        'start_date' => now()->startOfWeek()->addDays(3)->toDateString(),
        'due_at' => now()->startOfWeek()->addDays(12),
    ]));

    // Days 3,4,5,6 of ten → 4 hours. Counted where the work actually is, not
    // dropped whole into whichever week is being looked at.
    expect((float) $after['committed_hours'] - (float) $before['committed_hours'])->toBe(4.0)
        ->and($after['item_count'] - $before['item_count'])->toBe(1);
});

it('counts an unestimated item at the default and says that it did', function (): void {
    [$before, $after] = workloadAround($this->admin, SARAH_M, $this->monday, fn () => assignWork(SARAH_M, [
        'due_at' => now()->startOfWeek()->addDay(),
    ]));

    // The seeded organization default is 4 hours.
    expect((float) $after['committed_hours'] - (float) $before['committed_hours'])->toBe(4.0)
        ->and((float) $after['default_estimate_hours'])->toBe(4.0)
        // A bar built on unestimated work is a lie unless it admits it.
        ->and($after['unestimated_count'] - $before['unestimated_count'])->toBe(1);
});

it('counts undated work separately instead of pretending it is this week', function (): void {
    [$before, $after] = workloadAround(
        $this->admin,
        SARAH_M,
        $this->monday,
        fn () => assignWork(SARAH_M, ['estimate_hours' => 12]),
    );

    // Twelve hours that cannot be placed in any week add nothing to the total…
    expect((float) $after['committed_hours'])->toBe((float) $before['committed_hours'])
        ->and($after['item_count'])->toBe($before['item_count'])
        // …and are not invisible either.
        ->and($after['undated_count'] - $before['undated_count'])->toBe(1);
});

it('ignores work that is finished', function (): void {
    [$before, $after] = workloadAround($this->admin, SARAH_M, $this->monday, fn () => assignWork(SARAH_M, [
        'estimate_hours' => 8,
        'state_category' => 'done',
        'workflow_state_id' => DONE_STATE,
        'due_at' => now()->startOfWeek()->addDay(),
    ]));

    expect((float) $after['committed_hours'])->toBe((float) $before['committed_hours']);
});

it('reports over-commitment rather than clamping it', function (): void {
    // Sixty hours in one week against a 24-hour capacity. Clamping to 100%
    // would hide exactly the case a manager needs to see.
    assignWork(BUDI_PART_TIME, [
        'estimate_hours' => 60,
        'start_date' => now()->startOfWeek()->toDateString(),
        'due_at' => now()->startOfWeek()->addDays(6),
    ]);

    $load = workloadFor($this->admin, BUDI_PART_TIME, $this->monday);

    expect((float) $load['utilization'])->toBeGreaterThan(1.0);
});

it('lets a person see their own workload without any permission', function (): void {
    // Employees hold neither person.view_workload nor anything like it.
    $sarah = $this->loginAs('sarah@acme.test');

    $this->withToken($sarah)
        ->getJson('/api/v1/people/'.SARAH_M.'/workload')
        ->assertOk();
});

it('refuses a colleague nobody put them in charge of', function (): void {
    // Sarah is an Employee and does not manage Budi.
    $sarah = $this->loginAs('sarah@acme.test');

    $this->withToken($sarah)
        ->getJson('/api/v1/people/'.BUDI_PART_TIME.'/workload')
        ->assertForbidden();
});

it('gives a team rows, never one averaged number', function (): void {
    $team = $this->withToken($this->admin)
        ->getJson('/api/v1/teams/01900000-0000-7000-8000-000000000801/workload')
        ->assertOk();

    // An over-committed person beside an idle one averages to "fine", which is
    // the one reading of this data that helps nobody (docs/02 §11).
    expect($team->json('data'))->not->toBeEmpty()
        ->and($team->json('data.0'))->toHaveKeys(['name', 'capacity_hours', 'committed_hours'])
        ->and($team->json('meta.withheld_count'))->toBe(0);
});

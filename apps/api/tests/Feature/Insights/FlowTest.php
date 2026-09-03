<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Cycle time and throughput, asserted against ADR 0007.
 *
 * Every choice in that ADR is a choice somebody could reasonably have made
 * differently, which is exactly why each one gets a test: the risk with a
 * delivery metric is not that it breaks, it is that it quietly starts measuring
 * something else.
 */
const ORG = '01900000-0000-7000-8000-0000000000ac';
const WF = '01900001-0000-7000-8000-000000000001';
const S_IN_PROGRESS = '01900002-0000-7000-8000-000000000003';
const S_DONE = '01900002-0000-7000-8000-000000000006';
const S_CANCELLED = '01900002-0000-7000-8000-000000000008';
const AUTHOR = '01900000-0000-7000-8000-000000000203';
// An internal project, so the drill-through's visibility filter can reach the
// fixtures. Project-less work is private to the people involved in it
// (ADR 0004), which would hide these from an org admin — correctly.
const FLOW_PROJECT = '01900003-0000-7000-8000-000000000001';

beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');
});

/**
 * A work item with no transition history of its own.
 *
 * @param  array<string, mixed>  $attributes  overrides — a due date, or a null
 *                                            project for the department bucket
 */
function itemFor(string $category = 'done', array $attributes = []): string
{
    $id = (string) new UuidV7;

    DB::table('work_items')->insert(array_merge([
        'id' => $id,
        'organization_id' => ORG,
        'reference' => 'FLW-'.random_int(1000, 9999),
        'title' => 'Flow fixture',
        'project_id' => FLOW_PROJECT,
        'workflow_id' => WF,
        'workflow_state_id' => $category === 'done' ? S_DONE : S_CANCELLED,
        'state_category' => $category,
        'completed_at' => $category === 'done' ? now() : null,
        'created_by_membership_id' => AUTHOR,
        // array_merge, not `+`: the union operator keeps the LEFT value for a
        // duplicate key, so `['project_id' => null]` would be silently ignored
        // and the fixture would land in the default project.
    ], $attributes));

    return $id;
}

function transitioned(string $itemId, string $toCategory, string $at): void
{
    DB::table('work_item_transitions')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => ORG,
        'work_item_id' => $itemId,
        'to_state_id' => match ($toCategory) {
            'done' => S_DONE,
            'cancelled' => S_CANCELLED,
            default => S_IN_PROGRESS,
        },
        'to_category' => $toCategory,
        'occurred_at' => $at,
    ]);
}

function flow(string $token, string $from, string $to): array
{
    return test()->withToken($token)
        ->getJson("/api/v1/insights/flow?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');
}

it('measures from the first in_progress to the last done', function (): void {
    $item = itemFor();

    transitioned($item, 'in_progress', '2026-03-02 09:00:00');
    transitioned($item, 'done', '2026-03-04 09:00:00');

    $data = flow($this->admin, '2026-03-01', '2026-03-31');

    // Cast because JSON has one number type: a whole 48 arrives as int.
    // Two days, in hours. Not measured from creation: time in the backlog is a
    // prioritisation fact, not a delivery fact (ADR 0007).
    expect((float) $data['cycle_time_p50_hours'])->toBe(48.0)
        ->and($data['throughput'])->toBe(1)
        ->and($data['measured'])->toBe(1);
});

it('measures a reopened item once, across the whole span', function (): void {
    $item = itemFor();

    transitioned($item, 'in_progress', '2026-03-02 09:00:00');
    transitioned($item, 'done', '2026-03-03 09:00:00');
    transitioned($item, 'in_progress', '2026-03-04 09:00:00');
    transitioned($item, 'done', '2026-03-06 09:00:00');

    $data = flow($this->admin, '2026-03-01', '2026-03-31');

    // First start to last done: 4 days. Measuring each pass separately would
    // let a team improve the number by reopening and re-closing.
    expect((float) $data['cycle_time_p50_hours'])->toBe(96.0)
        ->and($data['measured'])->toBe(1);
});

it('excludes cancelled work entirely', function (): void {
    $item = itemFor('cancelled');

    transitioned($item, 'in_progress', '2026-03-02 09:00:00');
    transitioned($item, 'cancelled', '2026-03-02 10:00:00');

    // Counting an abandoned item as a one-hour completion would reward
    // abandoning work.
    expect(flow($this->admin, '2026-03-01', '2026-03-31')['throughput'])->toBe(0);
});

it('counts work that never started, but does not time it', function (): void {
    $straightToDone = itemFor();
    transitioned($straightToDone, 'done', '2026-03-05 09:00:00');

    $normal = itemFor();
    transitioned($normal, 'in_progress', '2026-03-02 09:00:00');
    transitioned($normal, 'done', '2026-03-03 09:00:00');

    $data = flow($this->admin, '2026-03-01', '2026-03-31');

    // It shipped, so it counts as throughput. There is nothing to measure, so
    // it is named rather than given an invented duration of zero.
    expect($data['throughput'])->toBe(2)
        ->and($data['measured'])->toBe(1)
        ->and($data['unmeasurable'])->toBe(1)
        ->and((float) $data['cycle_time_p50_hours'])->toBe(24.0);
});

it('reports percentiles a real item actually took', function (): void {
    // Durations of 1, 2, 3, 4 and 100 hours. A mean would be 22 — a number no
    // item here was anywhere near, which is why ADR 0007 refuses one.
    foreach ([1, 2, 3, 4, 100] as $hours) {
        $item = itemFor();
        transitioned($item, 'in_progress', '2026-03-02 00:00:00');
        transitioned($item, 'done', (new DateTimeImmutable('2026-03-02 00:00:00'))
            ->modify("+{$hours} hours")->format('Y-m-d H:i:s'));
    }

    $data = flow($this->admin, '2026-03-01', '2026-03-31');

    // Nearest-rank: every value returned is a duration something really took.
    expect((float) $data['cycle_time_p50_hours'])->toBe(3.0)
        ->and((float) $data['cycle_time_p85_hours'])->toBe(100.0);
});

it('breaks the window into weeks that sum to the total', function (): void {
    $first = itemFor();
    transitioned($first, 'in_progress', '2026-03-02 09:00:00');
    transitioned($first, 'done', '2026-03-03 09:00:00');

    $second = itemFor();
    transitioned($second, 'in_progress', '2026-03-09 09:00:00');
    transitioned($second, 'done', '2026-03-10 09:00:00');

    $data = flow($this->admin, '2026-03-01', '2026-03-31');

    $weekly = collect($data['weeks'])->sum(fn (array $week): int => $week['throughput']);

    expect($weekly)->toBe($data['throughput'])
        ->and(collect($data['weeks'])->pluck('week_start'))
        ->toContain('2026-03-02', '2026-03-09');
});

it('opens the number into the completions behind it', function (): void {
    $item = itemFor();
    transitioned($item, 'in_progress', '2026-03-02 09:00:00');
    transitioned($item, 'done', '2026-03-04 09:00:00');

    $response = $this->withToken($this->admin)
        ->getJson('/api/v1/insights/flow/items?from=2026-03-01&to=2026-03-31')
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $item);

    expect((float) $row['cycle_time_hours'])->toBe(48.0)
        ->and($response->json('meta.hidden_count'))->toBe(0);
});

it('returns the completions newest first', function (): void {
    // `whereIn` promises no order, so before this the drill-through came back
    // in whatever order Postgres happened to find the rows — stable enough to
    // pass by accident on a small table and not a property of the endpoint.
    $older = itemFor();
    transitioned($older, 'in_progress', '2026-03-02 09:00:00');
    transitioned($older, 'done', '2026-03-03 09:00:00');

    $newer = itemFor();
    transitioned($newer, 'in_progress', '2026-03-02 09:00:00');
    transitioned($newer, 'done', '2026-03-20 09:00:00');

    $ids = collect(
        $this->withToken($this->admin)
            ->getJson('/api/v1/insights/flow/items?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('data')
    )->pluck('id')->all();

    // Filtered to this test's own two rows: the window also contains seeded
    // completions, and asserting on positions in the whole list would be
    // asserting on the seed.
    $ours = array_values(array_filter($ids, fn ($id): bool => in_array($id, [$newer, $older], true)));

    expect($ours)->toBe([$newer, $older]);
});

it('is not available to someone without the reporting permission', function (): void {
    // Employees do not hold report.view. Delivery metrics are an
    // organization-level read, not a side effect of being able to see work.
    $this->withToken($this->loginAs('sarah@acme.test'))
        ->getJson('/api/v1/insights/flow')
        ->assertForbidden();
});

// ── overdue rate (ADR 0010) ─────────────────────────────────────────────────

it('measures the late rate on the work that had a date to miss', function (): void {
    $late = itemFor('done', ['due_at' => '2026-03-03 09:00:00']);
    transitioned($late, 'in_progress', '2026-03-02 09:00:00');
    transitioned($late, 'done', '2026-03-05 09:00:00');

    $onTime = itemFor('done', ['due_at' => '2026-03-10 09:00:00']);
    transitioned($onTime, 'in_progress', '2026-03-02 09:00:00');
    transitioned($onTime, 'done', '2026-03-05 09:00:00');

    $undated = itemFor();
    transitioned($undated, 'in_progress', '2026-03-02 09:00:00');
    transitioned($undated, 'done', '2026-03-05 09:00:00');

    $data = flow($this->admin, '2026-03-01', '2026-03-31');

    // Three completions, two of them dated, one of those late. The undated one
    // is out of the denominator: it cannot be late, and counting it as on time
    // would make a team that dates nothing look reliable (ADR 0010).
    expect($data['throughput'])->toBe(3)
        ->and($data['dated'])->toBe(2)
        ->and($data['completed_late'])->toBe(1)
        ->and((float) $data['late_rate'])->toBe(0.5);
});

it('has no late rate at all when nothing in the window carried a date', function (): void {
    $item = itemFor();
    transitioned($item, 'in_progress', '2026-03-02 09:00:00');
    transitioned($item, 'done', '2026-03-05 09:00:00');

    // No denominator, no rate. Zero would be a claim that everything landed on
    // time, which is not what "nobody set a date" means.
    expect(flow($this->admin, '2026-03-01', '2026-03-31')['late_rate'])->toBeNull();
});

it('opens the late rate onto the completions that missed their date', function (): void {
    $late = itemFor('done', ['due_at' => '2026-03-03 09:00:00']);
    transitioned($late, 'done', '2026-03-05 09:00:00');

    $onTime = itemFor('done', ['due_at' => '2026-03-10 09:00:00']);
    transitioned($onTime, 'done', '2026-03-05 09:00:00');

    $ids = collect(
        $this->withToken($this->admin)
            ->getJson('/api/v1/insights/flow/items?from=2026-03-01&to=2026-03-31&late=1')
            ->assertOk()
            ->json('data')
    )->pluck('id')->all();

    expect($ids)->toContain($late)
        ->and($ids)->not->toContain($onTime);
});

// ── departmental distribution (ADR 0010) ────────────────────────────────────

it('attributes completions to the project department, and gives work with none its own row', function (): void {
    $engineering = itemFor();
    transitioned($engineering, 'done', '2026-03-05 09:00:00');

    // Work with no project is private to the people involved in it (ADR 0004)
    // and is still counted here: this is an aggregate about the organization's
    // output, and the drill-through is what applies the reader's visibility.
    $noProject = itemFor('done', ['project_id' => null]);
    transitioned($noProject, 'done', '2026-03-06 09:00:00');

    $data = flow($this->admin, '2026-03-01', '2026-03-31');

    $rows = collect($data['departments']);

    expect($rows->sum('throughput'))->toBe($data['throughput'])
        ->and($rows->firstWhere('department_id', null)['throughput'])->toBe(1)
        ->and($rows->where('department_id', '!=', null)->sum('throughput'))->toBe(1);
});

it('narrows the drill-through to one department', function (): void {
    $engineering = itemFor();
    transitioned($engineering, 'done', '2026-03-05 09:00:00');

    $noProject = itemFor('done', ['project_id' => null]);
    transitioned($noProject, 'done', '2026-03-06 09:00:00');

    $department = collect(flow($this->admin, '2026-03-01', '2026-03-31')['departments'])
        ->firstWhere('department_id', '!=', null)['department_id'];

    $ids = collect(
        $this->withToken($this->admin)
            ->getJson("/api/v1/insights/flow/items?from=2026-03-01&to=2026-03-31&department_id={$department}")
            ->assertOk()
            ->json('data')
    )->pluck('id')->all();

    expect($ids)->toContain($engineering)
        ->and($ids)->not->toContain($noProject);
});

// ── bottlenecks (ADR 0010) ──────────────────────────────────────────────────

it('measures how long work waited in each category', function (): void {
    $item = itemFor();
    transitioned($item, 'todo', '2026-03-02 09:00:00');
    transitioned($item, 'in_progress', '2026-03-04 09:00:00');
    transitioned($item, 'done', '2026-03-05 09:00:00');

    $rows = collect(
        $this->withToken($this->admin)
            ->getJson('/api/v1/insights/bottlenecks?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('data')
    )->keyBy('category');

    // Two days in todo, one in progress. The `done` stay has no next
    // transition, so it has no duration — and inventing one would be inventing
    // a wait that has not finished (ADR 0010).
    expect((float) $rows['todo']['median_hours'])->toBe(48.0)
        ->and((float) $rows['in_progress']['median_hours'])->toBe(24.0)
        ->and($rows['todo']['steps'])->toBe(1);
});

it('counts a wait when it ended, not when it started', function (): void {
    $item = itemFor();
    // Entered todo before the window and left inside it: the wait finished in
    // this window, so this is the window it belongs to.
    transitioned($item, 'todo', '2026-02-20 09:00:00');
    transitioned($item, 'in_progress', '2026-03-02 09:00:00');
    transitioned($item, 'done', '2026-03-03 09:00:00');

    $rows = collect(
        $this->withToken($this->admin)
            ->getJson('/api/v1/insights/bottlenecks?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('data')
    )->keyBy('category');

    expect($rows['todo']['steps'])->toBe(1)
        ->and((float) $rows['todo']['median_hours'])->toBe(240.0);
});

it('lists a category work is sitting in even when nothing has left it', function (): void {
    $rows = collect(
        $this->withToken($this->admin)
            ->getJson('/api/v1/insights/bottlenecks?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('data')
    )->keyBy('category');

    // The seed has open work in categories nothing transitioned out of during
    // March. A step things go into and never come out of is the worst
    // bottleneck there is, and a median wait can never show it.
    expect($rows->keys())->toContain('todo')
        ->and($rows['todo']['waiting_now'])->toBeGreaterThan(0);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Project health, asserted against ADR 0008.
 *
 * Every threshold in that ADR is a number somebody agreed to, so every one gets
 * a test: the risk with a health signal is not that it breaks, it is that it
 * quietly starts calling a different situation amber.
 *
 * Each test builds its OWN project rather than measuring a seeded one. The seed
 * deliberately contains projects that are late, blocked and idle — which is
 * what makes it a good demo and a terrible fixture for a threshold test, since
 * the figures move whenever the seed does.
 */
const H_ORG = '01900000-0000-7000-8000-0000000000ac';
const H_WF = '01900001-0000-7000-8000-000000000001';
const H_S_TODO = '01900002-0000-7000-8000-000000000002';
const H_S_BLOCKED = '01900002-0000-7000-8000-000000000007';
const H_S_CANCELLED = '01900002-0000-7000-8000-000000000008';
const H_S_DONE = '01900002-0000-7000-8000-000000000006';
const H_AUTHOR = '01900000-0000-7000-8000-000000000203';

beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');
});

/**
 * A project of this test's own, so the thresholds are asserted against work
 * this test put there and nothing else.
 *
 * @return array{0: string, 1: string} the project's id and key
 */
function healthProject(?string $endDate = null): array
{
    $id = (string) new UuidV7;
    $key = 'HL'.random_int(100, 999);

    DB::table('projects')->insert([
        'id' => $id,
        'organization_id' => H_ORG,
        'key' => $key,
        'name' => 'Health fixture',
        'workflow_id' => H_WF,
        'status' => 'active',
        'visibility' => 'internal',
        'end_date' => $endDate,
        'owner_membership_id' => H_AUTHOR,
    ]);

    return [$id, $key];
}

/** @param  array<string, mixed>  $attributes */
function healthItem(string $projectId, string $category, array $attributes = []): string
{
    $id = (string) new UuidV7;

    DB::table('work_items')->insert([
        'id' => $id,
        'organization_id' => H_ORG,
        'reference' => 'HLT-'.random_int(10000, 99999),
        'title' => 'Health fixture item',
        'project_id' => $projectId,
        'workflow_id' => H_WF,
        // The state and the category have to agree: a row whose category says
        // one thing and whose state says another is a fixture that tests
        // nothing real.
        'workflow_state_id' => match ($category) {
            'done' => H_S_DONE,
            'blocked' => H_S_BLOCKED,
            'cancelled' => H_S_CANCELLED,
            default => H_S_TODO,
        },
        'state_category' => $category,
        // The check constraint requires the two to agree, and a done item with
        // no completion time makes every later metric wrong.
        'completed_at' => $category === 'done' ? now() : null,
        'created_by_membership_id' => H_AUTHOR,
    ] + $attributes);

    return $id;
}

function healthMoved(string $itemId, string $toCategory, string $at): void
{
    DB::table('work_item_transitions')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => H_ORG,
        'work_item_id' => $itemId,
        'to_state_id' => match ($toCategory) {
            'done' => H_S_DONE,
            'blocked' => H_S_BLOCKED,
            default => H_S_TODO,
        },
        'to_category' => $toCategory,
        'occurred_at' => $at,
    ]);
}

function healthMilestone(string $projectId, string $status, ?string $dueDate): void
{
    DB::table('milestones')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => H_ORG,
        'project_id' => $projectId,
        'name' => 'Health fixture milestone',
        'due_date' => $dueDate,
        'status' => $status,
        'completed_at' => $status === 'completed' ? now() : null,
    ]);
}

/** @return array<string, mixed> */
function health(string $token, string $key): array
{
    return test()->withToken($token)
        ->getJson("/api/v1/insights/projects/{$key}/health")
        ->assertOk()
        ->json('data');
}

// ── schedule ────────────────────────────────────────────────────────────────

it('calls a project with no end date unknown, never on track', function (): void {
    [$id, $key] = healthProject(endDate: null);
    healthItem($id, 'todo');

    $signal = health($this->admin, $key)['signals']['schedule'];

    // A project with no end date is not on schedule; it is a project with no
    // schedule. Zero is a claim, absent is an absence (ADR 0008).
    expect($signal['status'])->toBe('unknown')
        ->and($signal['end_date'])->toBeNull();
});

it('is off track when the end date has passed and work remains', function (): void {
    [$id, $key] = healthProject(endDate: now()->subDays(3)->toDateString());
    healthItem($id, 'todo');

    expect(health($this->admin, $key)['signals']['schedule']['status'])->toBe('off_track');
});

it('is on track past its end date once nothing is open', function (): void {
    [$id, $key] = healthProject(endDate: now()->subDays(3)->toDateString());
    healthItem($id, 'done');

    // A project that finished last week is not late. It is finished.
    expect(health($this->admin, $key)['signals']['schedule']['status'])->toBe('on_track');
});

it('warns while the end date is close and work remains', function (): void {
    [$id, $key] = healthProject(endDate: now()->addDays(5)->toDateString());
    healthItem($id, 'todo');

    expect(health($this->admin, $key)['signals']['schedule']['status'])->toBe('at_risk');
});

// ── overdue work ────────────────────────────────────────────────────────────

it('separates one late item from a systemically late project', function (): void {
    [$id, $key] = healthProject();

    // One overdue in ten is under the 20% share: a warning, not a verdict.
    healthItem($id, 'todo', ['due_at' => now()->subDay()]);

    foreach (range(1, 9) as $ignored) {
        healthItem($id, 'todo', ['due_at' => now()->addDays(30)]);
    }

    expect(health($this->admin, $key)['signals']['overdue_work']['status'])->toBe('at_risk');

    [$worse, $worseKey] = healthProject();
    healthItem($worse, 'todo', ['due_at' => now()->subDay()]);
    healthItem($worse, 'todo', ['due_at' => now()->addDays(30)]);

    $signal = health($this->admin, $worseKey)['signals']['overdue_work'];

    expect($signal['status'])->toBe('off_track')
        ->and((float) $signal['share'])->toBe(0.5);
});

it('does not count finished work as overdue', function (): void {
    [$id, $key] = healthProject();
    healthItem($id, 'done', ['due_at' => now()->subDays(30)]);

    // It was late. It is also done, and a project cannot work its way back to
    // green if finished work keeps counting against it.
    expect(health($this->admin, $key)['signals']['overdue_work']['status'])->toBe('on_track');
});

// ── blocked work ────────────────────────────────────────────────────────────

it('escalates a block that has become a stall', function (): void {
    [$id, $key] = healthProject();
    $item = healthItem($id, 'blocked');
    healthMoved($item, 'blocked', now()->subDays(2)->toDateTimeString());

    expect(health($this->admin, $key)['signals']['blocked_work']['status'])->toBe('at_risk');

    [$stalled, $stalledKey] = healthProject();
    $old = healthItem($stalled, 'blocked');
    healthMoved($old, 'blocked', now()->subDays(9)->toDateTimeString());

    $signal = health($this->admin, $stalledKey)['signals']['blocked_work'];

    expect($signal['status'])->toBe('off_track')
        ->and($signal['longest_days'])->toBe(9);
});

it('measures a block from the latest one, not the first', function (): void {
    [$id, $key] = healthProject();
    $item = healthItem($id, 'blocked');

    healthMoved($item, 'blocked', now()->subDays(40)->toDateTimeString());
    healthMoved($item, 'todo', now()->subDays(30)->toDateTimeString());
    healthMoved($item, 'blocked', now()->subDays(2)->toDateTimeString());

    // It is the CURRENT wait that is the problem. The item was unblocked once
    // and blocked again; reporting 40 days would be describing history.
    $signal = health($this->admin, $key)['signals']['blocked_work'];

    expect($signal['longest_days'])->toBe(2)
        ->and($signal['status'])->toBe('at_risk');
});

it('counts a blocked item with no transition history without timing it', function (): void {
    [$id, $key] = healthProject();
    healthItem($id, 'blocked');

    $signal = health($this->admin, $key)['signals']['blocked_work'];

    // Seeded or imported work sits in a state nothing recorded it entering.
    // It is blocked; how long is not knowable, and null says so.
    expect($signal['count'])->toBe(1)
        ->and($signal['longest_days'])->toBeNull()
        ->and($signal['status'])->toBe('at_risk');
});

// ── milestones ──────────────────────────────────────────────────────────────

it('says unknown about milestones a project does not have', function (): void {
    [$id, $key] = healthProject();
    healthItem($id, 'todo');

    expect(health($this->admin, $key)['signals']['milestones']['status'])->toBe('unknown');
});

it('grades one past-due milestone differently from two', function (): void {
    [$one, $oneKey] = healthProject();
    healthMilestone($one, 'open', now()->subDays(2)->toDateString());
    healthMilestone($one, 'open', now()->addDays(20)->toDateString());

    expect(health($this->admin, $oneKey)['signals']['milestones']['status'])->toBe('at_risk');

    [$two, $twoKey] = healthProject();
    healthMilestone($two, 'open', now()->subDays(2)->toDateString());
    healthMilestone($two, 'at_risk', now()->subDays(9)->toDateString());

    expect(health($this->admin, $twoKey)['signals']['milestones']['status'])->toBe('off_track');
});

it('does not hold a late but completed milestone against a project', function (): void {
    [$id, $key] = healthProject();
    healthMilestone($id, 'completed', now()->subDays(30)->toDateString());

    $health = health($this->admin, $key);

    expect($health['signals']['milestones']['status'])->toBe('on_track')
        ->and($health['past_due_milestones'])->toBe([]);
});

// ── activity ────────────────────────────────────────────────────────────────

it('flags a project nothing has moved in, and forgives a finished one', function (): void {
    [$id, $key] = healthProject();
    $item = healthItem($id, 'todo');
    healthMoved($item, 'todo', now()->subDays(20)->toDateTimeString());

    $signal = health($this->admin, $key)['signals']['activity'];

    expect($signal['status'])->toBe('off_track')
        ->and($signal['days_since'])->toBe(20)
        ->and($signal['stale_count'])->toBe(1);

    [$finished, $finishedKey] = healthProject();
    $closed = healthItem($finished, 'done');
    healthMoved($closed, 'done', now()->subDays(60)->toDateTimeString());

    // Quiet is what a finished project is supposed to be. A signal that flags
    // one is a signal people learn to ignore.
    expect(health($this->admin, $finishedKey)['signals']['activity']['status'])->toBe('on_track');
});

// ── the overall verdict ─────────────────────────────────────────────────────

it('reports the worst signal, not the average of them', function (): void {
    [$id, $key] = healthProject(endDate: now()->addDays(90)->toDateString());

    // Four signals are fine and one is not. An average would call this project
    // healthy, which is the one reading that helps nobody.
    $item = healthItem($id, 'blocked');
    healthMoved($item, 'blocked', now()->subDays(30)->toDateTimeString());

    $health = health($this->admin, $key);

    expect($health['status'])->toBe('off_track')
        ->and($health['signals']['schedule']['status'])->toBe('on_track')
        ->and($health['signals']['overdue_work']['status'])->toBe('on_track');
});

it('is unknown about a project nobody has set up', function (): void {
    [, $key] = healthProject();

    // No end date, no work, no milestones: every signal has nothing to judge.
    // "Healthy" would be a claim about a project that does not exist yet.
    expect(health($this->admin, $key)['status'])->toBe('unknown');
});

it('computes progress from the work items, not the unwritten cache', function (): void {
    [$id, $key] = healthProject();

    healthItem($id, 'done');
    healthItem($id, 'todo');
    healthItem($id, 'todo');
    // Cancelled work is neither progress nor remaining work, so it is out of
    // the denominator entirely.
    healthItem($id, 'cancelled');

    expect((float) health($this->admin, $key)['progress_percent'])->toBe(33.3);
});

// ── the drill-through ───────────────────────────────────────────────────────

it('opens each signal onto exactly the records it counted', function (): void {
    [$id, $key] = healthProject();

    healthItem($id, 'todo', ['due_at' => now()->subDay()]);
    healthItem($id, 'todo', ['due_at' => now()->addDays(30)]);
    $blocked = healthItem($id, 'blocked');
    healthMoved($blocked, 'blocked', now()->subDays(9)->toDateTimeString());

    $health = health($this->admin, $key);

    foreach (['overdue' => 'overdue_work', 'blocked' => 'blocked_work'] as $signal => $name) {
        $response = $this->withToken($this->admin)
            ->getJson("/api/v1/insights/projects/{$key}/health/items?signal={$signal}")
            ->assertOk();

        // The list and the count are two implementations of one predicate, and
        // this is the assertion that keeps them the same one.
        expect($response->json('meta.total'))->toBe($health['signals'][$name]['count'])
            ->and($response->json('data'))->toHaveCount($health['signals'][$name]['count'])
            ->and($response->json('meta.hidden_count'))->toBe(0);
    }
});

it('refuses a signal it does not define', function (): void {
    [, $key] = healthProject();

    $this->withToken($this->admin)
        ->getJson("/api/v1/insights/projects/{$key}/health/items?signal=vibes")
        ->assertStatus(422);
});

it('does not exist for someone who cannot see the project', function (): void {
    // FIN is private and Sarah is not a member. Not "forbidden" — a private
    // project should not be discoverable by asking about its health (ADR 0004).
    $this->withToken($this->loginAs('sarah@acme.test'))
        ->getJson('/api/v1/insights/projects/FIN/health')
        ->assertNotFound();
});

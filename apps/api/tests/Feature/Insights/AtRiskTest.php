<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Manager Home's risk list, asserted against ADR 0009.
 *
 * Ahmad is the seed's Engineering Manager: four direct reports and owner of the
 * ENG project, which is exactly the shape the screen is for. Sarah reports to
 * him and manages nobody, which is the other case worth proving — the screen is
 * selected by the data, not by a role name.
 *
 * Every assertion is **by work item id**. The seed deliberately contains work
 * that is overdue, blocked and stale, so asserting on counts or on the first
 * row would be asserting on the seed rather than on the rule.
 */
const R_ORG = '01900000-0000-7000-8000-0000000000ac';
const R_WF = '01900001-0000-7000-8000-000000000001';
const R_ENG = '01900003-0000-7000-8000-000000000001';
const R_AHMAD = '01900000-0000-7000-8000-000000000202';
const R_SARAH = '01900000-0000-7000-8000-000000000203';
const R_S_TODO = '01900002-0000-7000-8000-000000000002';
const R_S_PROGRESS = '01900002-0000-7000-8000-000000000003';
const R_S_BLOCKED = '01900002-0000-7000-8000-000000000007';

beforeEach(function (): void {
    // Ahmad owns ENG and manages four people; the risk list is his by data.
    $this->manager = $this->loginAs('ahmad@acme.test');
});

/** @param  array<string, mixed>  $attributes */
function riskItem(string $category = 'todo', array $attributes = [], bool $assign = true): string
{
    $id = (string) new UuidV7;

    DB::table('work_items')->insert([
        'id' => $id,
        'organization_id' => R_ORG,
        'reference' => 'RSK-'.random_int(10000, 99999),
        'title' => 'Risk fixture',
        'project_id' => R_ENG,
        'workflow_id' => R_WF,
        'workflow_state_id' => match ($category) {
            'in_progress' => R_S_PROGRESS,
            'blocked' => R_S_BLOCKED,
            default => R_S_TODO,
        },
        'state_category' => $category,
        'created_by_membership_id' => R_AHMAD,
    ] + $attributes);

    if ($assign) {
        DB::table('work_item_assignments')->insert([
            'id' => (string) new UuidV7,
            'organization_id' => R_ORG,
            'work_item_id' => $id,
            'membership_id' => R_SARAH,
            'role' => 'assignee',
            'is_primary' => true,
        ]);
    }

    return $id;
}

function riskMoved(string $itemId, string $toCategory, string $at): void
{
    DB::table('work_item_transitions')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => R_ORG,
        'work_item_id' => $itemId,
        'to_state_id' => match ($toCategory) {
            'in_progress' => R_S_PROGRESS,
            'blocked' => R_S_BLOCKED,
            default => R_S_TODO,
        },
        'to_category' => $toCategory,
        'occurred_at' => $at,
    ]);
}

function riskBlocks(string $blocker, string $blockedItem): void
{
    DB::table('work_item_dependencies')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => R_ORG,
        'work_item_id' => $blockedItem,
        'depends_on_work_item_id' => $blocker,
        'type' => 'blocks',
    ]);
}

/** @return array<string, array<string, mixed>> the rows, keyed by work item id */
function atRisk(string $token): array
{
    return collect(
        test()->withToken($token)
            ->getJson('/api/v1/insights/at-risk?limit=50')
            ->assertOk()
            ->json('data')
    )->keyBy('id')->all();
}

it('names an overdue item as overdue', function (): void {
    $item = riskItem('todo', ['due_at' => now()->subDays(2)]);

    expect(atRisk($this->manager)[$item]['reasons'])->toContain('overdue');
});

it('names work that other open work is waiting on', function (): void {
    $blocker = riskItem();
    $waiting = riskItem();
    riskBlocks($blocker, $waiting);

    $rows = atRisk($this->manager);

    // The cost of this one is somebody else's week, and it is the one reason a
    // manager cannot see by looking at the item alone.
    expect($rows[$blocker]['reasons'])->toContain('blocking')
        ->and($rows[$blocker]['blocking_count'])->toBe(1)
        // The item doing the waiting is not itself at risk for waiting.
        ->and($rows)->not->toHaveKey($waiting);
});

it('stops counting a dependency once the waiting work is finished', function (): void {
    $blocker = riskItem();
    $waiting = riskItem('done', ['completed_at' => now()]);
    riskBlocks($blocker, $waiting);

    // A dependency from finished work is history, not risk.
    expect(atRisk($this->manager))->not->toHaveKey($blocker);
});

it('calls unassigned work risky only once its date is close', function (): void {
    $soon = riskItem('todo', ['due_at' => now()->addDays(3)], assign: false);
    $someday = riskItem('todo', ['due_at' => now()->addDays(60)], assign: false);
    $undated = riskItem('todo', [], assign: false);

    $rows = atRisk($this->manager);

    // Unassigned work with no date is a backlog, not a risk.
    expect($rows[$soon]['reasons'])->toContain('unassigned')
        ->and($rows)->not->toHaveKey($someday)
        ->and($rows)->not->toHaveKey($undated);
});

it('calls work that has not moved in a week stalled', function (): void {
    $stalled = riskItem('in_progress');
    riskMoved($stalled, 'in_progress', now()->subDays(10)->toDateTimeString());

    $moving = riskItem('in_progress');
    riskMoved($moving, 'in_progress', now()->subDay()->toDateTimeString());

    $rows = atRisk($this->manager);

    expect($rows[$stalled]['reasons'])->toContain('stalled')
        ->and($rows[$stalled]['days_since_move'])->toBe(10)
        ->and($rows)->not->toHaveKey($moving);
});

it('measures staleness from creation when nothing has ever moved', function (): void {
    // Started, then never transitioned again: its age is measured from
    // creation, so work that was picked up and forgotten is stalled rather
    // than invisible.
    $never = riskItem('in_progress', ['created_at' => now()->subDays(30)]);

    expect(atRisk($this->manager)[$never]['reasons'])->toContain('stalled');
});

it('leaves an old backlog item alone', function (): void {
    // A todo item that has sat for a month is a prioritisation fact, not a
    // risk. Counting it would put most of a real organization's work on this
    // list, which is the same as putting none of it there (ADR 0009).
    $old = riskItem('todo', ['created_at' => now()->subDays(40)]);

    expect(atRisk($this->manager))->not->toHaveKey($old);
});

it('does not call blocked work stalled as well', function (): void {
    $item = riskItem('blocked');
    riskMoved($item, 'blocked', now()->subDays(30)->toDateTimeString());

    // Blocked work is waiting on something named; that is a different problem
    // with a different fix, and saying both would count it twice in one list.
    expect(atRisk($this->manager)[$item]['reasons'])->toBe(['blocked']);
});

it('leaves ordinary open work alone', function (): void {
    $fine = riskItem('todo', ['due_at' => now()->addDays(20)]);

    // Assigned, dated, moving: nothing here needs a manager. Notably it is NOT
    // risky because its assignee might be over capacity — capacity is a fact
    // about a person and lives in the team capacity block (ADR 0009).
    expect(atRisk($this->manager))->not->toHaveKey($fine);
});

it('orders a row by its worst reason', function (): void {
    $stalled = riskItem('in_progress');
    riskMoved($stalled, 'in_progress', now()->subDays(10)->toDateTimeString());

    $overdue = riskItem('todo', ['due_at' => now()->subDays(1)]);

    $ids = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/insights/at-risk?limit=50')
            ->assertOk()
            ->json('data')
    )->pluck('id')->all();

    $ours = array_values(array_filter($ids, fn ($id): bool => in_array($id, [$stalled, $overdue], true)));

    // A commitment already missed comes before work that is merely quiet.
    expect($ours)->toBe([$overdue, $stalled]);
});

it('carries every reason that applies, not only the worst', function (): void {
    $item = riskItem('todo', ['due_at' => now()->subDays(3)], assign: false);
    $waiting = riskItem();
    riskBlocks($item, $waiting);

    expect(atRisk($this->manager)[$item]['reasons'])
        ->toBe(['overdue', 'blocking', 'unassigned']);
});

it('is empty for someone who manages nobody and owns no project', function (): void {
    // Sarah has work, some of it overdue. None of it is HERS to manage, and an
    // empty list is how the page knows not to render Manager Home at all.
    riskItem('todo', ['due_at' => now()->subDays(2)]);

    expect(atRisk($this->loginAs('sarah@acme.test')))->toBe([]);
});

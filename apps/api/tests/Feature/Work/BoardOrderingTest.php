<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\DB;

/**
 * Fractional ordering: a board drag writes ONE row (docs/03 §3).
 *
 * The property under test is not "the card moved" — it is "the card moved
 * without touching its neighbours". Integer ordering passes the first and fails
 * the second, and the difference only shows up as slowness on a busy board.
 */
beforeEach(function (): void {
    $this->manager = $this->loginAs('ahmad@acme.test');
});

it('reorders by writing a single row', function (): void {
    $column = WorkItemModel::query()
        ->where('project_id', '01900003-0000-7000-8000-000000000001')
        ->where('state_category', 'todo')
        ->orderBy('position')
        ->take(3)
        ->get();

    [$first, $second, $third] = [$column[0], $column[1], $column[2]];
    $untouched = $third->position;

    $this->withToken($this->manager)->postJson("/api/v1/work-items/{$third->reference}/move", [
        'before_id' => $first->id,
        'after_id' => $second->id,
    ])->assertOk();

    // Neighbours keep their positions — nothing was renumbered.
    expect($first->fresh()->position)->toBe($first->position)
        ->and($second->fresh()->position)->toBe($second->position)
        ->and($third->fresh()->position)->not->toBe($untouched);
});

it('places the moved card between its new neighbours', function (): void {
    $column = WorkItemModel::query()
        ->where('project_id', '01900003-0000-7000-8000-000000000001')
        ->where('state_category', 'todo')
        ->orderBy('position')
        ->take(3)
        ->get();

    $this->withToken($this->manager)->postJson("/api/v1/work-items/{$column[2]->reference}/move", [
        'before_id' => $column[0]->id,
        'after_id' => $column[1]->id,
    ])->assertOk();

    $order = WorkItemModel::query()
        ->whereIn('id', $column->pluck('id'))
        ->orderBy('position')
        ->pluck('reference')
        ->all();

    expect($order)->toBe([$column[0]->reference, $column[2]->reference, $column[1]->reference]);
});

it('moves a card to another column and changes its state together', function (): void {
    $item = WorkItemModel::query()->where('reference', 'ENG-144')->firstOrFail();

    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-144/move', [
        'to_state_id' => '01900002-0000-7000-8000-000000000004',   // In Review
        'before_id' => null,
        'after_id' => null,
    ])->assertOk();

    expect($item->fresh()->state_category)->toBe('in_review');

    // A drag between columns IS a status change, so it must leave the same
    // trail as one made from the dropdown.
    $this->assertDatabaseHas('activity_logs', [
        'subject_type' => 'work_item',
        'subject_id' => $item->id,
        'verb' => 'status_changed',
    ]);
});

it('returns the board as columns rather than one query per column', function (): void {
    $board = $this->withToken($this->manager)
        ->getJson('/api/v1/projects/ENG/board')
        ->assertOk()
        ->json('data');

    expect($board['columns'])->not->toBeEmpty()
        ->and($board['columns'][0])->toHaveKeys(['state', 'items']);
});


/**
 * The board's move endpoint reaches a transition, so it must ask what the
 * transition endpoint asks.
 *
 * `POST /move` carrying a `to_state_id` performs a real state change —
 * `WorkItemService::move()` delegates to `transition()`. Its route can only
 * declare `permission:work_item.update`, because the same endpoint also just
 * reorders a column, and reordering is not a transition. So for a while the
 * weaker endpoint was the way in: anyone holding `work_item.update` could move
 * work between columns without holding `work_item.transition`.
 *
 * It never looked like a hole, because the workflow guards still ran. The move
 * was legal by the graph; it was simply taken by somebody the policy next door
 * would have refused. No seeded role can reach it — every role with `update`
 * also has `transition` — which is why this test builds the split itself, and
 * why it matters now: Phase 7 ships a custom role builder whose whole purpose
 * is letting an administrator create exactly this combination.
 */
it('refuses a cross-column move to someone who may edit but not transition', function (): void {
    withoutTransitionPermission('01900000-0000-7000-8000-000000000402');   // manager

    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-144/move', [
        'to_state_id' => '01900002-0000-7000-8000-000000000004',   // In Review
    ])->assertForbidden();

    expect(WorkItemModel::query()->where('reference', 'ENG-144')->value('state_category'))
        ->not->toBe('in_review');
});

/**
 * The other half, and the reason the check reads the request body rather than
 * sitting on the route: taking `work_item.transition` away must not take the
 * board's ordering with it.
 */
it('still allows reordering within a column without the transition permission', function (): void {
    withoutTransitionPermission('01900000-0000-7000-8000-000000000402');

    $column = WorkItemModel::query()
        ->where('project_id', '01900003-0000-7000-8000-000000000001')
        ->where('state_category', 'todo')
        ->orderBy('position')
        ->take(3)
        ->get();

    $this->withToken($this->manager)->postJson("/api/v1/work-items/{$column[2]->reference}/move", [
        'before_id' => $column[0]->id,
        'after_id' => $column[1]->id,
        // Sent, and equal to where the card already is — which is what a board
        // posts when a card is dropped back into the column it came from.
        'to_state_id' => $column[2]->workflow_state_id,
    ])->assertOk();
});

/**
 * Take one permission off a role, and make the resolver notice.
 *
 * The revocation is rolled back with the test's transaction; the cache is not,
 * so it is invalidated explicitly — a stale `true` here would make the test
 * pass by measuring the wrong thing.
 */
function withoutTransitionPermission(string $roleId): void
{
    DB::table('role_permissions')
        ->where('role_id', $roleId)
        ->whereIn('permission_id', DB::table('permissions')->where('key', 'work_item.transition')->pluck('id'))
        ->delete();

    $resolver = app(PermissionResolver::class);

    foreach (DB::table('membership_roles')->where('role_id', $roleId)->pluck('membership_id')->all() as $membershipId) {
        $resolver->invalidate((string) $membershipId);
    }
}

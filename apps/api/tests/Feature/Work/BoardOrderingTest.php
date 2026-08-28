<?php

declare(strict_types=1);

use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;

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

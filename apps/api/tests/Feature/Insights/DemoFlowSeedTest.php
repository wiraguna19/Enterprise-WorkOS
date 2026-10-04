<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The demo seed gives the Flow page something to show.
 *
 * The generator marked dozens of items done and the Flow page still said
 * "nothing completed in this window" on every fresh database, because flow is
 * computed from transitions and the seed wrote transitions for one item only.
 * These assert the history now exists and is shaped like history: inside the
 * default window, ordered, after each item's creation, and with a late rate
 * that is neither nothing nor everything.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');
});

it('has completions, cycle times and a late rate in the default window', function (): void {
    $flow = $this->withToken($this->admin)
        ->getJson('/api/v1/insights/flow')
        ->assertOk()
        ->json('data');

    expect($flow['throughput'])->toBeGreaterThan(20)
        // Not equal to throughput: a story item finished without a history of
        // its own counts as delivered and is rightly left untimed.
        ->and($flow['measured'])->toBeGreaterThan(20)
        ->and($flow['cycle_time_p50_hours'])->not->toBeNull()
        ->and((float) $flow['cycle_time_p85_hours'])->toBeGreaterThan((float) $flow['cycle_time_p50_hours'])
        ->and($flow['late_rate'])->not->toBeNull()
        ->and((float) $flow['late_rate'])->toBeGreaterThan(0.0)
        ->and((float) $flow['late_rate'])->toBeLessThan(1.0);
});

it('shows where finished work waited', function (): void {
    $rows = collect(
        $this->withToken($this->admin)
            ->getJson('/api/v1/insights/bottlenecks')
            ->assertOk()
            ->json('data'),
    )->keyBy('category');

    expect($rows->get('todo')['steps'] ?? 0)->toBeGreaterThan(0)
        ->and($rows->get('in_progress')['steps'] ?? 0)->toBeGreaterThan(0);
});

it('writes every seeded step after its item was created, and in order', function (): void {
    // Compared in SQL, not in PHP: the claim is about the rows as stored.
    $beforeCreation = DB::table('work_item_transitions as t')
        ->join('work_items as w', 'w.id', '=', 't.work_item_id')
        ->whereRaw("t.id::text LIKE '01900029-%'")
        ->whereColumn('t.occurred_at', '<', 'w.created_at')
        ->count();

    $outOfOrder = DB::selectOne(<<<'SQL'
        SELECT count(*) AS n
          FROM (
            SELECT occurred_at,
                   lag(occurred_at) OVER (PARTITION BY work_item_id ORDER BY id) AS previous
              FROM work_item_transitions
             WHERE id::text LIKE '01900029-%'
          ) steps
         WHERE previous IS NOT NULL AND occurred_at <= previous
        SQL)->n;

    $endsElsewhere = DB::table('work_item_transitions as t')
        ->join('work_items as w', 'w.id', '=', 't.work_item_id')
        ->whereRaw("t.id::text LIKE '01900029-%'")
        ->where('t.to_category', 'done')
        ->whereColumn('t.occurred_at', '!=', 'w.completed_at')
        ->count();

    expect($beforeCreation)->toBe(0)
        ->and((int) $outOfOrder)->toBe(0)
        ->and($endsElsewhere)->toBe(0);
});

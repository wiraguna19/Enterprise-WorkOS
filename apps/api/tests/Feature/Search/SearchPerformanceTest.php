<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * What makes search fast, asserted as mechanism rather than as milliseconds
 * (docs/10 Phase 5 exit criteria, docs/11 §3).
 *
 * The roadmap states the target as "under 300 ms on seeded data", and this file
 * deliberately does not assert that number. Two reasons, and the second is the
 * important one:
 *
 *   1. Wall-clock assertions are machine-dependent and flaky, which is why
 *      every other performance test here bounds query COUNT instead.
 *   2. At seed scale the timing would pass no matter what. A few hundred rows
 *      fit in one page of memory, so Postgres will correctly choose a
 *      sequential scan and answer in single-digit milliseconds WITH OR WITHOUT
 *      the GIN indexes. A green timing test on this data would therefore prove
 *      nothing about the thing it appears to protect.
 *
 * So what is asserted is what actually decays: the indexes the query plan will
 * need once the table is large, and the number of round trips a keystroke
 * costs. Whether the real system meets 300 ms at real volume is a question for
 * a load fixture, and it is not answerable here.
 */
beforeEach(function (): void {
    $this->token = $this->loginAs('ahmad@acme.test');
});

it('keeps the full-text indexes the query plan will need', function (string $index, string $table): void {
    $definition = DB::table('pg_indexes')
        ->where('indexname', $index)
        ->value('indexdef');

    expect($definition)->not->toBeNull("The index {$index} on {$table} is gone.")
        // GIN specifically: a btree on a tsvector is accepted by Postgres and
        // is useless for @@, so "an index exists" is not the assertion.
        ->and($definition)->toContain('USING gin');
})->with([
    ['idx_wi_search', 'work_items'],
    ['idx_projects_search', 'projects'],
    ['idx_comments_search', 'comments'],
]);

it('searches every source in a bounded number of queries', function (): void {
    // Three sources, each with its own visibility rule, plus the permission
    // lookups those rules share. What must never appear is per-ROW work: the
    // palette fires this on every keystroke, so an N+1 here is felt as the
    // product being slow rather than as one endpoint being slow.
    $log = queryLogFor(function (): void {
        $this->withToken($this->token)->getJson('/api/v1/search?q=pool')->assertOk();
    });

    $queries = count($log);

    expect($queries)->toBeLessThan(12, "search ran {$queries} queries: ".queryBreakdown($log));
});

it('does not pay per result', function (): void {
    // The guard against a regression a single count would hide: if round trips
    // track the number of hits, something is being resolved one row at a time.
    // `matched_on` and the rank were both computed per row in PHP at one point,
    // which is exactly this shape.
    $one = queryLogFor(function (): void {
        $this->withToken($this->token)->getJson('/api/v1/search?q=runbook')->assertOk();
    });

    $many = queryLogFor(function (): void {
        $this->withToken($this->token)->getJson('/api/v1/search?q=search')->assertOk();
    });

    // A small tolerance, not equality: an eager load only fires for an arm that
    // returned rows, so a broader query can legitimately cost a query or two
    // more. Per-row work would show up as tens, not as two.
    expect(count($many))->toBeLessThanOrEqual(
        count($one) + 2,
        sprintf(
            'a broader query cost %d round trips against %d for a narrow one: %s',
            count($many),
            count($one),
            queryBreakdown($many),
        ),
    );
});

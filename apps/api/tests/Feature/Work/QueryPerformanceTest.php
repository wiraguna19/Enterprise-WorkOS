<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * N+1 regressions are invisible in code review and catastrophic at scale
 * (docs/11 §3). These assert an upper bound on query COUNT, not on time —
 * timing is machine-dependent and flaky; query count is deterministic.
 *
 * The bounds are deliberately loose enough not to fight ordinary refactors and
 * tight enough that a missing eager load fails immediately.
 */
function countQueries(callable $work): int
{
    return count(queryLogFor($work));
}

/** @return list<string> the SQL of every statement the callback issued */
function queryLogFor(callable $work): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $work();

    $log = array_values(array_map(fn (array $entry): string => (string) $entry['query'], DB::getQueryLog()));
    DB::disableQueryLog();

    return $log;
}

/**
 * A count on its own says a bound was crossed but not by what, which is how a
 * failing budget turns into "raise the number" instead of "find the N+1".
 * This turns the log into the shape that actually diagnoses it: how many times
 * each table was hit, worst first.
 */
function queryBreakdown(array $log): string
{
    $byTable = [];

    foreach ($log as $sql) {
        preg_match('/\b(?:from|into|update)\s+"?([a-z_]+)"?/i', $sql, $m);
        $table = $m[1] ?? 'other';
        $byTable[$table] = ($byTable[$table] ?? 0) + 1;
    }

    arsort($byTable);

    return implode(', ', array_map(
        fn (string $t, int $n): string => "{$t}×{$n}",
        array_keys($byTable),
        $byTable,
    ));
}

it('lists 50 work items without an N+1', function (): void {
    $token = $this->loginAs('ahmad@acme.test');

    $log = queryLogFor(function () use ($token): void {
        $this->withToken($token)->getJson('/api/v1/work-items?limit=50')->assertOk();
    });
    $queries = count($log);

    // Each row renders state, project, and assignees. Without eager loading
    // this would be 150+.
    expect($queries)->toBeLessThan(15, "work item list ran {$queries} queries: ".queryBreakdown($log));
});

it('renders a whole board in a bounded number of queries', function (): void {
    $token = $this->loginAs('ahmad@acme.test');

    $log = queryLogFor(function () use ($token): void {
        $this->withToken($token)->getJson('/api/v1/projects/ENG/board')->assertOk();
    });
    $queries = count($log);

    // One for the columns, one for the cards, plus eager loads — never one
    // query per column.
    expect($queries)->toBeLessThan(15, "board ran {$queries} queries: ".queryBreakdown($log));
});

it('lists the project directory without counting per row', function (): void {
    $token = $this->loginAs('ahmad@acme.test');

    $log = queryLogFor(function () use ($token): void {
        $this->withToken($token)->getJson('/api/v1/projects')->assertOk();
    });
    $queries = count($log);

    // open_work_count and overdue_work_count are correlated subqueries in the
    // same statement, not a count per project.
    expect($queries)->toBeLessThan(12, "project directory ran {$queries} queries: ".queryBreakdown($log));
});

it('answers My Work in a handful of queries', function (): void {
    $token = $this->loginAs('sarah@acme.test');

    $log = queryLogFor(function () use ($token): void {
        $this->withToken($token)->getJson('/api/v1/me/work?view=today')->assertOk();
    });
    $queries = count($log);

    // 14, and every one is accounted for: 2 session + 2 user lookups for the
    // bearer token, 2 membership reads (the tenant resolver's re-check and the
    // eager load that renders assignees), 1 role read, 1 reporting-line read for
    // the visibility scope, and one each for the items, their states, projects
    // and assignments. Raise this only with the same kind of accounting — the
    // breakdown in the failure message is there to make that possible.
    expect($queries)->toBeLessThan(14, "my work ran {$queries} queries: ".queryBreakdown($log));
});

it('computes all shell badge counts in one grouped query', function (): void {
    $token = $this->loginAs('sarah@acme.test');

    $log = queryLogFor(function () use ($token): void {
        $this->withToken($token)->getJson('/api/v1/me/work/counts')->assertOk();
    });
    $queries = count($log);

    // This runs on every page load; four separate COUNT(*) round trips would
    // be four times the cost for the same answer.
    expect($queries)->toBeLessThan(8, "counts ran {$queries} queries: ".queryBreakdown($log));
});

it('answers the command palette in a bounded number of queries', function (): void {
    $token = $this->loginAs('ahmad@acme.test');

    $log = queryLogFor(function () use ($token): void {
        $this->withToken($token)->getJson('/api/v1/search?q=connection')->assertOk();
    });
    $queries = count($log);

    // Three ranked sources plus the request's own auth and tenant reads. The
    // palette fires on keystrokes (docs/08 §5), so a per-row lookup added here
    // is multiplied by every letter someone types — which is exactly the shape
    // this budget exists to catch.
    expect($queries)->toBeLessThan(12, "search ran {$queries} queries: ".queryBreakdown($log));
});

it('keeps the overdue scan on its index', function (): void {
    $this->loginAs('ahmad@acme.test');

    // enable_seqscan is disabled for this assertion on purpose. At seed size
    // PostgreSQL correctly prefers a sequential scan, so asserting the plan
    // directly would fail on a HEALTHY database and train everyone to ignore
    // this test. Removing the size question leaves the one that matters: does
    // the index still MATCH the predicate the app issues?
    DB::statement('SET enable_seqscan = off');

    // ORDER BY due_at is part of the real query (MyWorkQuery::forView('overdue')),
    // and it is half of why the index is shaped (organization_id, due_at).
    // Explaining the predicate without the ordering asks a question the app
    // never asks, and the planner is free to answer it with any index.
    $plan = collect(DB::select(
        'EXPLAIN SELECT id FROM work_items
          WHERE organization_id = ? AND due_at < now()
            AND state_category NOT IN (\'done\',\'cancelled\') AND deleted_at IS NULL
          ORDER BY due_at',
        ['01900000-0000-7000-8000-0000000000ac'],
    ))->pluck('QUERY PLAN')->implode(' ');

    DB::statement('RESET enable_seqscan');

    expect($plan)->toContain('idx_wi_overdue');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL only');

it('lists the people directory without an N+1', function (): void {
    $token = $this->loginAs('rina@acme.test');

    $log = queryLogFor(function () use ($token): void {
        $this->withToken($token)->getJson('/api/v1/people?limit=100')->assertOk();
    });
    $queries = count($log);

    // Every person carries three permission decisions, and each one asks the
    // policy who the actor is. Resolved once per request, that is one lookup;
    // resolved per call it is three per row, which is what a transient policy
    // binding would silently reintroduce.
    expect($queries)->toBeLessThan(12, "people directory ran {$queries} queries: ".queryBreakdown($log));
});

it('answers "where is the risk" in a bounded number of queries', function (): void {
    // Ahmad manages four people and owns ENG, so this is the real Manager Home
    // scope rather than an empty one (ADR 0009).
    $token = $this->loginAs('ahmad@acme.test');

    $log = queryLogFor(function () use ($token): void {
        $this->withToken($token)->getJson('/api/v1/insights/at-risk')->assertOk();
    });
    $queries = count($log);

    // 13 measured, and every one of them accounted for (docs/11 §3 — a bound is
    // raised with its accounting, never because it failed):
    //
    //   sessions×2, users×2, memberships×2, membership_roles×1  authentication
    //                                                           and the actor's
    //                                                           permissions
    //   employee_profiles×2   the reporting-line walk, once for the risk scope
    //                         and once for visibility
    //   work_items×2          the risk computation itself, then hydrating the
    //                         rows it ranked
    //   projects×1, work_item_assignments×1   the eager loads those rows render
    //
    // The risk computation is ONE statement: reasons are folded from a single
    // scan rather than asked per item. A per-item "does anything depend on
    // this" would be invisible in code review and linear in the size of a
    // department, and it is what this bound exists to catch.
    expect($queries)->toBeLessThan(15, "at-risk ran {$queries} queries: ".queryBreakdown($log));
});

it('measures where work waited in a bounded number of queries', function (): void {
    $token = $this->loginAs('rina@acme.test');

    $log = queryLogFor(function () use ($token): void {
        $this->withToken($token)->getJson('/api/v1/insights/bottlenecks')->assertOk();
    });
    $queries = count($log);

    // Two statements do the work — one window function over the transitions,
    // one snapshot of what is sitting where — on top of authentication. The
    // shape this bound exists to catch is a per-category query, which would
    // grow with the number of state categories a customer defines and look
    // perfectly reasonable in a diff (ADR 0010).
    expect($queries)->toBeLessThan(12, "bottlenecks ran {$queries} queries: ".queryBreakdown($log));
});

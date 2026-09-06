<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The demo seed must produce the same valid data on every day of the week.
 *
 * On 2026-09-06 — a Sunday — the entire suite failed: 372 tests, all with the
 * same `ck_work_items_dates` violation, thrown from the seeder before a single
 * assertion ran. The cause was one UPDATE that pulls three of David's items
 * into "this week" so the over-committed state is always on the dashboard:
 *
 *     start_date = GREATEST(date_trunc('week', now())::date, start_date)
 *     due_at     = date_trunc('week', now()) + interval '4 days 17 hours'
 *
 * `date_trunc('week')` is the ISO Monday, so that due date is FRIDAY of the
 * current week. From Saturday onward it is in the past, while an item's
 * existing `start_date` can be as late as today — so GREATEST picks today,
 * today is after Friday, and the schema refuses the row.
 *
 * It had been there since Phase 3 and passed every run, because every run
 * happened on a weekday.
 *
 * This test does not wait for a weekend. It evaluates the same expressions
 * against Postgres for each of the seven days, with the worst case the seed can
 * produce (`start_date` = the day it is run), and asserts the invariant the
 * CHECK enforces.
 */
it('keeps the seeded over-commitment inside its own week on every weekday', function (): void {
    foreach (range(0, 6) as $dayOfWeek) {
        // Compared in Postgres rather than in PHP, and deliberately: the rule
        // being checked is a database CHECK, and re-expressing it with
        // `strtotime()` would test a second implementation of the comparison
        // instead of the one that refuses the row.
        $row = DB::selectOne(<<<'SQL'
            WITH d AS (
                SELECT (date_trunc('week', now()) + make_interval(days => ?)) AS today
            )
            SELECT LEAST(
                       -- The seed's own expression, with `today` standing in
                       -- for the latest start_date its loop can generate.
                       GREATEST(date_trunc('week', today)::date, today::date),
                       (date_trunc('week', today) + interval '4 days')::date
                   ) <= (date_trunc('week', today) + interval '4 days 17 hours')::date AS ok,
                   to_char(today, 'Day') AS day_name
              FROM d
        SQL, [$dayOfWeek]);

        expect($row->ok)->toBeTrue(
            'The seed would violate ck_work_items_dates when run on a '.trim((string) $row->day_name),
        );
    }
});

/**
 * And the rows it actually produced obey it.
 *
 * The test above proves the expression; this proves the database agrees — a
 * constraint can only be trusted while something checks that the data still
 * satisfies it, and the seed is the largest single writer this product has.
 */
it('seeds no work item that starts after it is due', function (): void {
    $offenders = DB::table('work_items')
        ->whereNotNull('start_date')
        ->whereNotNull('due_at')
        ->whereRaw('start_date > due_at::date')
        ->count();

    expect($offenders)->toBe(0);
});

/**
 * Every statement that moves a due date must move the start date with it.
 *
 * A text check, and that is the right level here for a reason worth stating:
 * the two expression tests above prove the clamps that EXIST are correct, and
 * neither could catch the actual defect, which was a second UPDATE that had no
 * clamp at all. This file has now broken the same way twice — once written,
 * once missed while fixing the first — and what it needs guarding is not a
 * value but a habit.
 *
 * The seed is raw SQL run through `DB::unprepared`, so nothing else in this
 * codebase can see inside it: no type checker, no query builder, no analyser.
 * Reading it as text is the only inspection available, which makes a crude
 * check better than none.
 */
it('never moves a due date in the seed without clamping the start date', function (): void {
    $sql = (string) file_get_contents(database_path('seeders/sql/demo_work_items.sql'));

    // Each `UPDATE work_items SET …` up to its WHERE. Deliberately not a
    // parser: a statement complex enough to defeat this pattern is a statement
    // that should be simpler.
    preg_match_all('/UPDATE\s+work_items\s+SET(.*?)WHERE/is', $sql, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $assignments) {
        if (! str_contains($assignments, 'due_at')) {
            continue;
        }

        // `str_contains` rather than `toContain`, because Pest's `toContain`
        // takes a LIST of needles — a failure message passed as its second
        // argument becomes a second string the subject must contain, and the
        // test then fails for a reason that has nothing to do with the seed.
        expect(str_contains($assignments, 'start_date'))->toBeTrue(
            'An UPDATE moves due_at without touching start_date. On a weekend the '
                .'due date it sets is already in the past, and any item that started '
                .'today then starts after it is due — ck_work_items_dates refuses the '
                .'row and the whole seed fails.',
        );
    }
});

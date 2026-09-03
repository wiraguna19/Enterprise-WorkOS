<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/**
 * The scheduler runs on exactly ONE instance, guarded by a cache lock. Without
 * the lock, a two-container deployment sends every deadline reminder twice
 * (docs/01 §7).
 */

// Keeps log partitions ahead of the calendar. If this stops running, rows land
// in the DEFAULT partition — which still works, and is alerted on, rather than
// failing writes (docs/03 §6).
Schedule::command('governance:ensure-log-partitions')
    ->monthlyOn(1, '02:00')
    ->onOneServer()
    ->withoutOverlapping();

// Expired sessions are deleted rather than left to accumulate; the audit trail
// of logins lives in audit_logs and is unaffected.
Schedule::command('identity:prune-expired-sessions')
    ->dailyAt('03:00')
    ->onOneServer();

// Recurring work appears on its own (docs/10, Phase 5).
//
// Every fifteen minutes rather than hourly: a rule set for 09:00 should produce
// work at 09:00, not at whatever o'clock the hourly tick lands on. The
// materialiser claims rows with SKIP LOCKED, so an overlapping run takes
// different rules rather than the same one twice — withoutOverlapping() is
// belt to that braces.
Schedule::command('workflow:materialize-recurrences')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// The project directory's progress bar (docs/02 §5). Cached rather than
// computed per row: the directory renders every project a person can see, and
// a live percentage per row is one aggregate query per row.
//
// Hourly, because progress is a figure people glance at rather than act on
// within the minute, and because the alternative — recomputing on every work
// item transition — puts a table-wide aggregate on the hot path of the most
// frequent write in the product. `progress_cached_at` travels with the number
// so the interface can say how old it is instead of implying it is live.
Schedule::command('work:roll-up-project-progress')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

// Export files do not live forever, and the expiry column would be a lie
// without something acting on it (ADR 0011). Daily: an export lives a week, so
// an hour's imprecision at the end of it costs nobody anything.
Schedule::command('insights:prune-expired-exports')
    ->dailyAt('04:00')
    ->onOneServer();

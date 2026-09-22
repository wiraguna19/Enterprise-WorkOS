# ADR 0037 — A failed job has somewhere to land

- **Status:** accepted
- **Date:** 2026-09-22
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/01` §7, ADR 0014, ADR 0036

## Context

`config/queue.php` has named `failed_jobs` since the framework was installed.
The table has never existed.

A job that exhausts its retries is handed to the failure store; the store's
INSERT fails; the job is gone — payload, exception, timing, all of it. This
product runs rule evaluation, notification delivery and recurrence materialising
on that queue, so the gap is not academic: **"a job died and nobody can say
which, or why"** was the true state of this system for seven phases.

It was found from the outside, and only because something else went wrong. A
rule card claimed three failures, the run log had none, and the obvious next
question — where did those attempts go — had no table to ask (ADR 0036).

## Decision

**Create the table, in Laravel's own shape.** The framework writes these rows
and `queue:retry` reads them, so the columns are not ours to improve.
`database-uuids` is the configured driver, and the uuid is what makes a failed
job addressable by a stable name at a terminal.

**A guard test that asks the CONFIG, not a hard-coded name.** It reads
`queue.failed.driver`, `table` and `database`, skips politely if the store is
not a database at all, and otherwise asserts the table is there. Moving the
store later is then caught here rather than by somebody wondering where their
jobs went. A second test writes a row, because a shape that merely looks right
is not the contract — the framework's insert is.

**Pruned after a fortnight**, on the schedule beside the other prunes. Long
enough that a failure over a weekend is readable on Monday; short enough that
the table does not become an archive nobody opens. The command shipped with the
framework; what it lacked here was a table to write to and anything to call it.

## Consequences

- Failures are now *recorded*, not *surfaced*. There is no screen for them and
  this ADR does not invent one: the audience for a dead job is whoever runs the
  system, and they have a terminal. A `queue:failed` listing that nobody has
  permission to see is not worth a page until somebody asks for it.
- **The seven phases before this are not recoverable.** Whatever died, died.
  Worth writing down, because the temptation on finding a gap like this is to
  say it is fixed and move on, and the honest statement is that it is fixed
  going forward.
- This is the third instance this week of the same shape — a write path with no
  read path, or a read path with nothing written (ADR 0019, ADR 0029, ADR 0035).
  The common root is a configuration or a column that names a thing nobody ever
  exercised. The guard tests this project keeps adding are the only reason any
  of them surfaced.

# ADR 0003 — The actual-hours rollup is recalculated in the write transaction, not by a job

- **Status:** accepted
- **Date:** 2026-08-27
- **Phase:** 5 (Collaboration & Time)
- **Relates to:** `docs/03-database-schema.md` §4, `docs/02-domain-model.md` §11

## Context

`docs/03` §4 describes `work_items.actual_hours_cache` as "derived from
`time_entries`, refreshed by job. Never authoritative."

Two claims are packed into that sentence, and only one of them is load-bearing.
**Derived and never authoritative** is the important one: the entries are the
truth, and the column is a convenience for lists and boards that would otherwise
aggregate on every render. **Refreshed by job** is a mechanism, chosen when the
column was written down in Phase 2 and nothing was writing to it yet.

Implementing it as a job in Phase 5 turned out to buy nothing and cost something
specific: a person logs four hours, the response returns the work item, and the
total on it is still the old one. The number is wrong for as long as the queue
takes, and it is wrong *on the screen of the person who just changed it* — which
is the one moment a stale derived value is guaranteed to be noticed and reported
as a bug.

## Decision

`TimeEntryService` recalculates the rollup inside the same transaction as every
entry write and delete.

It recalculates by **summing the entries**, never by adding or subtracting a
delta from the previous value. A delta is how a derived number drifts: one
rolled-back transaction, one concurrent delete, and the total is wrong with
nothing to point at. The sum is a single aggregate over
`idx_time_entries_work_item`, on a row set that is small by construction — one
work item's own entries.

The column stays non-authoritative. Nothing reads it to make a decision; the
time-entries endpoint returns both the summed total and the cached one side by
side, so a disagreement is visible immediately rather than inferred later.

## Consequences

**Bought:** the number is correct the moment it is read, including in the
response to the write that changed it. No queue dependency for a feature that
otherwise has none — time logging works with the worker stopped.

**Paid:** a write that logs time now costs one extra aggregate and one extra
update. Both are indexed and bounded by the entries on a single work item.

**Trigger to revisit:** a bulk import path that writes thousands of entries in
one request, or a work item accumulating entries in the tens of thousands. At
that point the sum belongs behind a job again — and the honest version of that
change is a batch that recalculates each affected item once, not the per-write
job this ADR replaced.

## Alternatives considered

**A database trigger.** Keeps the rollup correct no matter who writes, including
a hand-run SQL fix. Rejected for the reason the codebase avoids triggers
generally: the arithmetic becomes invisible to anyone reading the PHP, and the
test suite can no longer make it fail on purpose.

**No cache at all — aggregate on read.** Honest, and correct by construction.
Rejected because the board and every work list render the number per row, which
is the N+1 shape `docs/11` §3 budgets against.

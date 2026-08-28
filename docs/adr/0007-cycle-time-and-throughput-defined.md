# ADR 0007 — Cycle time and throughput, defined

- **Status:** accepted
- **Date:** 2026-08-28
- **Phase:** 6 (Insights)
- **Relates to:** `docs/10-roadmap.md` Phase 6, `docs/03-database-schema.md` §4,
  `docs/02-domain-model.md` §11, `docs/08-ux-navigation.md` §3

## Context

`docs/10` asks Phase 6 for "cycle-time and throughput metrics from
`work_item_transitions`", and `docs/08` §3 puts them on Organization Home. Unlike
workload, **neither is defined anywhere in `docs/`** — and the phase's own exit
criterion is that every number traces to a documented definition. So the
definition has to be written before the number can ship, and this is it.

Both metrics have several defensible definitions. What makes one wrong is not
the choice but leaving it implicit: two teams comparing "cycle time" that mean
different things is worse than either team having no number.

## Decision

### Cycle time

**From the first entry into `in_progress` to the last entry into `done`, per
work item.**

- **Start is `in_progress`, not creation.** Time in the backlog is a prioritisation
  fact, not a delivery fact — an item that sat for three months and then took a
  day was a one-day piece of work. (The creation-to-done span is *lead time*, a
  different and also useful number; it is not this one, and if it ships later it
  ships under its own name.)
- **End is `done`.** `cancelled` items are excluded entirely: they never
  finished, and counting them as fast completions would reward abandoning work.
- **A reopened item is measured once, first start to last done.** It really did
  take that long. Measuring each pass separately would let a team improve its
  numbers by reopening and re-closing.
- **Blocked time is included.** Waiting on someone else is part of how long the
  work took, and subtracting it produces a number that flatters the process by
  hiding the thing most worth fixing.
- Items with no `in_progress` transition are excluded and counted as such: a
  jump straight to `done` has no measurable duration, and inventing one is
  worse than admitting there is nothing to measure.

### Throughput

**The count of items entering `done` in a period**, by the transition's
`occurred_at`. A reopened-and-reclosed item counts on each completion here, and
that is deliberate: throughput measures flow through the step, and the work of
finishing something twice was really done twice.

### Reported as percentiles, never as a mean

p50 and p85, with the sample size beside them. Cycle-time distributions are
heavily skewed — one ninety-day item drags a mean somewhere no actual item ever
was — and a mean is the single easiest way to publish a number nobody can act
on. p85 is the one a team can make a promise from.

### Never per person

Aggregated by period and optionally by project. There is no per-assignee
breakdown, and adding one is a decision to be taken against `docs/02` §11
explicitly rather than by extending a filter — that section rules out reducing
individual performance to a ranked number, and per-person cycle time is exactly
that number with a neutral name.

## Consequences

**Bought:** a delivery signal computed from data collected since Phase 4, with
no new writes and nothing to backfill. `work_item_transitions` was built
append-only for this.

**Paid:** items that predate the transition log, or that were closed by a path
that recorded no transition, are invisible to it. The sample size is returned
with every figure so a thin period is recognisable as thin rather than as fast.

**Trigger to revisit:** a customer whose workflow has more than one "working"
category — a `testing` or `review` state they consider part of delivery. The
start and end categories then belong in organization settings rather than in
this definition, which is a configuration change and not a redefinition.

## Alternatives considered

**Creation to done (lead time) as the headline number.** Closer to what a
customer experiences, and worth shipping later under its own name. Rejected as
*the* cycle-time definition because it measures the backlog as much as the work,
so it moves when prioritisation changes and stays put when delivery improves —
the opposite of what a team needs from it.

**Excluding blocked time.** Produces a "touch time" that flatters the process.
Rejected: the number exists to expose where work waits.

**Mean with a standard deviation.** Familiar, and wrong for this shape of data.

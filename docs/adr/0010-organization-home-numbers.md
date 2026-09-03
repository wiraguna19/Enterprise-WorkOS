# ADR 0010 — Overdue rate, departmental distribution, and what counts as a bottleneck

- **Status:** accepted
- **Date:** 2026-09-02
- **Phase:** 6 (Insights)
- **Relates to:** `docs/08-ux-navigation.md` §3 (Organization Home),
  `docs/10-roadmap.md` Phase 6, `docs/02-domain-model.md` §11, ADR 0004,
  ADR 0007, ADR 0008

## Context

`docs/08` §3 asks Organization Home for "trends over time (throughput, cycle
time, overdue rate), distribution across departments, and a bottleneck list —
not a scoreboard of individuals". ADR 0007 defined throughput and cycle time.
The other three are undefined, and one of them cannot be computed the way the
phrase implies.

## Decision

### Overdue rate means late COMPLETIONS, not the overdue backlog

The natural reading of "overdue rate over time" is "what share of open work was
overdue, week by week". **That number cannot be computed, because nothing
recorded it.** `due_at` is mutable and only its current value is stored, and
there is no snapshot of what was open on a Tuesday in June. Reconstructing it
from today's dates would produce a chart that changes retroactively every time
someone edits a due date — a trend line that rewrites its own history.

So the rate is defined on the completions instead: **of the work completed in a
period that had a due date, the share that was completed after it.** That is
computable from the two facts the system does keep — the `done` transition's
`occurred_at` (ADR 0007) and the item's due date — and it answers the question
the chart is for: are we finishing things when we said we would.

- **Items with no due date are excluded from the rate entirely** and counted as
  such. They cannot be late; putting them in the denominator would make a team
  that estimates nothing look reliable, which is the opposite of true.
- The rate is **folded from the same completions** the throughput and
  percentiles come from, so the three figures on a row cannot disagree about
  which items they describe (the rule ADR 0008 and the workload drill-through
  both follow).
- If a snapshot of open work is ever wanted, it is a rollup job writing a new
  table, and it is a different number with a different name. It is not this one
  computed better.

### Departmental distribution is by the project's department, and "none" is a row

Each completion is attributed to its project's department. Two cases need a
rule, and both get a visible one rather than a silent drop:

- **Work in a project with no department** and **work with no project at all**
  land in a single `null` department row, rendered as "No department". Dropping
  them would make the rows fail to add up to the throughput above them, which
  is the defect `meta.hidden_count` exists to prevent elsewhere.
- Work with no project is private to the people involved in it (ADR 0004), and
  it is counted here anyway. This is an aggregate, not a list: the count is a
  fact about the organization's output, and the drill-through applies the
  reader's visibility and reports what it withheld. That split is the house
  rule; nothing new is being decided.

**No per-person distribution, ever.** `docs/02` §11 rules out reducing
individual performance to a ranked number, and "throughput by assignee" is that
number with a neutral name. The department rows exist because a department is a
unit of capacity and budget; a person is not.

### A bottleneck is where work waited, measured from consecutive transitions

For each item, two consecutive transitions bound one step: it entered a state
category at the first and left at the second. **A bottleneck row is one state
category, with the nearest-rank median time items spent there and the number of
items sitting in it right now.**

- **A step counts in the window when it was LEFT in the window**, matching
  throughput's rule of counting the moment the thing happened.
- **A step still in progress has no duration** and is excluded from the median.
  It is counted in `waiting_now` instead, which is the honest pair: how long the
  waits that finished took, and how many are still going.
- **Nearest-rank median, never a mean**, for ADR 0007's reason — time-in-state
  is as skewed as cycle time, and every figure reported should be a duration
  something actually took.
- `waiting_now` is a snapshot and is labelled as one on the page. It is the one
  figure here that changes without any work happening, because it changes when
  time passes.

The bottleneck list is **not sorted by which category holds the most items**. A
backlog with two hundred items in it is not a bottleneck; a review step with a
four-day median is. Sorted by median wait, descending.

## Consequences

**Bought:** the three remaining Organization Home figures, from
`work_item_transitions` and existing columns, with no new writes and nothing to
backfill — and an overdue trend that does not rewrite itself when someone edits
a date.

**Paid:** the overdue rate answers a slightly different question than the phrase
in `docs/08` suggests, so the page prints the definition next to it. The
bottleneck query reads every transition of every item completed in the window;
`QueryPerformanceTest` gets a budget, and this is the read most likely to want
the cached snapshots `docs/10` mentions once there is real volume.

**Trigger to revisit:** a customer asking "how much work was overdue last
quarter". That is the snapshot number, and the answer is a rollup table, not a
change here.

## Alternatives considered

**Reconstructing the historical overdue backlog from current due dates.**
Rejected above: it produces a chart whose past changes when someone edits a
date, which is worse than not having the chart.

**Ranking bottlenecks by queue length.** Intuitive and wrong — it puts the
backlog first every time, and the backlog is where work is supposed to wait.

**Time-in-state per workflow STATE rather than per category.** More precise and
not comparable across organizations, since states are customisable per customer
(`docs/02` §7) while the seven categories are closed. Categories first; a
per-state view is a later drill-down inside a category, not a replacement.

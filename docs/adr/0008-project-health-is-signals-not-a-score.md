# ADR 0008 — Project health is a set of signals, not a score

- **Status:** accepted
- **Date:** 2026-09-02
- **Phase:** 6 (Insights)
- **Relates to:** `docs/10-roadmap.md` Phase 6, `docs/05-api-architecture.md`
  (`GET /insights/projects/{id}/health`), `docs/08-ux-navigation.md` §2,
  `docs/02-domain-model.md` §5, ADR 0007

## Context

`docs/10` asks Phase 6 for "project health scoring (explainable, not a black
box)", `docs/08` puts health on the project overview, and `docs/05` reserves the
endpoint. As with cycle time, **nothing in `docs/` says what health means** — and
the phase's exit criterion is that every number traces to a documented
definition and opens onto the records behind it. So the definition comes first.

The parenthesis in the roadmap is the whole requirement. A health score is a
number a manager will repeat in a status meeting, and the meeting immediately
asks "why amber?". A weighted composite cannot answer that question: the honest
answer is "0.3 × overdue share + 0.2 × blocked count + …", which nobody can act
on, and every attempt to explain it after the fact is a reconstruction rather
than the reason.

## Decision

### Health is five named signals, each with its own verdict

`schedule`, `overdue_work`, `blocked_work`, `milestones`, `activity`. Each
returns a status, the count behind it, and — through the drill-through — the
records that count is made of. **There is no weighted composite and no 0–100
number.** The signals are the explanation; a score would be a second thing to
explain.

### The overall status is the WORST signal, never an average

An average lets a project that is on fire in one dimension and idle in four read
as "mostly fine" — the same failure as averaging a team's workload into one
number (ADR 0007's sibling reasoning in `docs/02` §11). The reason a project is
amber is always one specific signal, and taking the worst preserves which.

### Four statuses, and `unknown` is not `on_track`

`on_track` · `at_risk` · `off_track` · `unknown`.

A signal with nothing to judge returns `unknown` and is excluded from the
overall verdict. A project with no end date is not on schedule; it is a project
with no schedule. Reporting that as green is the workload `time_off_hours`
mistake in another costume: **zero is a claim, absent is an absence.** If every
signal is `unknown`, the project's health is `unknown`, and that is a truthful
answer about a project nobody has set up.

### The thresholds

Stated here in full, because a threshold that lives only in code is a threshold
nobody agreed to. "Open" means a work item whose state category is not `done` or
`cancelled`.

| Signal | `unknown` | `on_track` | `at_risk` | `off_track` |
|---|---|---|---|---|
| `schedule` | no end date | end date is in the future, or no open work remains | end date within 14 days and open work remains | end date has passed and open work remains |
| `overdue_work` | project has no work items | no open item is past due | at least one is | overdue items are ≥ 20% of open work |
| `blocked_work` | project has no work items | nothing blocked | something is | something has been blocked ≥ 7 days |
| `milestones` | project has no milestones | none past due, none missed | exactly one past due | two or more past due, or any marked `missed` |
| `activity` | no work item has ever moved | last movement within 7 days, or no open work remains | last movement 7–14 days ago | last movement more than 14 days ago |

Three of these deserve their reasons written down:

- **A project with no work items is `unknown` on every signal, and so overall.**
  "Nothing is overdue" is true of an empty project and useless, and five such
  truths add up to a healthy verdict about a project nobody has set up. A
  project whose work is all *finished* is a different case and stays green: it
  really does have nothing overdue and nothing blocked.

- **`schedule` goes green when the open work is gone**, whatever the date says.
  A project that finished last week is not late; it is finished. The date only
  becomes a verdict when there is still work that has to fit inside it.
- **`activity` is silent about finished projects** for the same reason. Quiet is
  what a completed project is supposed to be, and a metric that flags it will be
  ignored within a week — and then ignored on the project where it mattered.

### The API returns numbers and statuses; the interface writes the sentence

The response carries `status`, the count, and the threshold values that produced
it. It does not carry English prose explaining the verdict. Prose in an API
response is a localisation trap and a second place for the rule to live; the
page prints the rule beside the number, as `/reports` prints the cycle-time
definition.

### Counts are facts about the project; lists are facts about the reader

`GET /insights/projects/{key}/health/items?signal=…` applies the caller's work
item visibility and returns `hidden_count`, exactly as the workload and flow
drill-throughs do. A count that shrank because of who is reading would make two
managers' status reports disagree about the same project.

### Addressed by key, not id

`docs/05` writes `/insights/projects/{id}/health`. Every other project route in
the product is `/projects/{key}`, references are what people paste to each
other, and one endpoint addressing projects differently would be a trap rather
than a convention. The deviation is here rather than silent.

## Consequences

**Bought:** an explanation a manager can act on — "amber because two milestones
are past due, here they are" — computed live from tables that have existed since
Phase 2, with nothing new stored and nothing to backfill.

**Paid:** five signals is five queries' worth of work per project, so this does
not belong in the project directory as a per-row column. Health is a page, not a
list column; the directory keeps its cheap counts.

**Found while writing this:** `projects.progress_cache` is written by nothing.
The column, its `progress_cached_at` companion and the progress bar in the
project directory have existed since Phase 2, and the rollup job that was to
fill them was never built — so every project in the directory renders 0%. Health
computes its own progress from the work items rather than reading the cache. The
directory's bar is a separate defect and is fixed with the rollup job, not by
quietly changing what the bar reads.

**Trigger to revisit:** an organization that wants its own thresholds. They are
constants in one class for exactly that reason; moving them into organization
settings is a configuration change and not a redefinition. A weighted score is
not on that path — if one is ever wanted, it is a new number with a new name,
and these signals stay underneath it.

## Alternatives considered

**A 0–100 score with weights.** Familiar from every project tool on the market,
and the reason "health" is a word managers distrust. Rejected: it answers "how
bad" and refuses to answer "why", and the roadmap asked for the opposite in
parentheses.

**Red/amber/green with no numbers.** Explainable only in the trivial sense.
Rejected: the count is what makes a verdict arguable, and a verdict nobody can
argue with is one nobody acts on.

**Deriving health from the schedule alone** (burn-up against the end date).
Rejected: it says nothing about work that is blocked or abandoned, which is what
a project is usually dying of when the dates still look fine.

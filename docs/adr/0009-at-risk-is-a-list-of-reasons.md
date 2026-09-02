# ADR 0009 — "At risk" is a list of reasons, and who sees it is decided by the data

- **Status:** accepted
- **Date:** 2026-09-02
- **Phase:** 6 (Insights)
- **Relates to:** `docs/08-ux-navigation.md` §3 (Manager Home),
  `docs/02-domain-model.md` §11, `docs/06-auth-and-authorization.md` §2,
  ADR 0004, ADR 0008

## Context

`docs/08` §3 sketches Manager Home around one question — "where is the risk?" —
and shows four example rows: an overdue item that blocks two others, an item due
tomorrow with no update in four days, an unassigned item due in two days, and an
item whose assignee is over capacity. It defines none of them, and the phase's
exit criterion still applies: every number traces to a documented definition.

Two questions have to be answered before the screen can be built. What makes an
item "at risk", and whose risk is it.

## Decision

### An item carries its reasons, and sorts by its worst

Five reasons, each computed independently. A row returns **every** reason that
applies to it and is ordered by the most severe, in this order:

| Reason | Meaning |
|---|---|
| `overdue` | open, and its due date has passed |
| `blocking` | open, and at least one other open item depends on it |
| `unassigned` | open, due within 7 days or already due, and nobody holds it |
| `stalled` | in progress or in review, and no transition for 7 days |
| `blocked` | in the `blocked` state category |

The order is by how soon the consequence lands, not by how bad it feels.
`overdue` is a commitment already missed. `blocking` outranks the rest because
the cost is somebody else's week, and it is the one reason a manager cannot
discover by looking at the item alone.

This is the same shape as ADR 0008's health signals, for the same reason: the
useful answer to "why is this row here" is a reason, and a composite risk score
answers "how bad" while refusing to answer "why". A row that lists
`overdue, blocking` says what to do; a row that says `risk: 0.72` does not.

### Capacity is a fact about a person, not about an item

`docs/08` shows "over capacity this week" as a row in the risk list. It is not
one here. An over-committed assignee would attach the same reason to every one
of their twelve items, so the list would fill with one person's name and bury
the four items that are actually in trouble. Capacity appears where it belongs —
the team capacity block, one row per person, already computed by `WorkloadQuery`
(`docs/02` §11) — and stays out of the item list.

### The scope is what the reader manages, defined by data

The rows are open work that is either **assigned to someone in the reader's
reporting line** (at any depth, the same transitive walk
`MembershipPolicy::viewWorkload` uses) or **in a project they own or manage**.
`WorkItemVisibility` is applied on top, so nothing here can widen what a person
may read.

**No permission gates this section, and no role string selects it.** Manager
Home appears for a person because the query returned something to manage, not
because their role is called "manager": roles are per organization and
customisable (`docs/06` §2), so keying a whole screen to the word would break
for the first customer who renames it. A team lead with two reports and no
management title gets the screen; a "Manager" with an empty line does not, and
correctly — a screen answering "where is the risk" for nobody is a screen with
nothing on it.

### The reporting-line walk moves to Organization

The recursive CTE over `employee_profiles` existed privately inside
`Work\WorkItemVisibility`; this would have been its second copy, and a rule
about who reports to whom that lives in two modules will eventually disagree
with itself. It moves to `Organization\Application\Query\ReportingLine`, which
is the module that owns the table, and both callers use it.

### Manager Home is composed by the page, not by one endpoint

The screen shows risk, approvals, team capacity and project health. Those come
from four endpoints across three modules, and the page assembles them. There is
no `/insights/manager-home` returning all four, because Insights must not import
Approval — `docs/04` §3 has Approval below Notification and imported by neither
Search, Calendar nor Insights, and an endpoint that "just needs the approval
count" is exactly how that edge gets added. The composition cost is four
parallel requests in one server render.

## Consequences

**Bought:** a screen whose every row states why it is there and links to the
thing it describes, computed from tables that have existed since Phase 2 and 4,
with no new writes.

**Paid:** the risk query touches work items, assignments, transitions and
dependencies for a scope that can be a whole department. It is capped and
ordered in SQL rather than in PHP, and `QueryPerformanceTest` gets a budget for
it. If a customer's reporting line reaches thousands of people, this becomes the
first Insights read that wants the cached snapshots `docs/10` mentions.

**Trigger to revisit:** a sixth reason. Five is already at the limit of what a
person reads before scanning past, and the next candidate should probably
replace one rather than join it.

## Alternatives considered

**A single risk score per item.** Sortable, compact, and unexplainable.
Rejected for the reasons ADR 0008 rejects a health score; the arguments are the
same and so is the answer.

**Gating the screen on a `team.manage`-style permission.** Simpler to reason
about, and wrong: permissions describe what someone may do, and this screen is
about what someone is responsible for. A person can hold no special permission
and still have four reports.

**Including capacity as a per-item reason, as `docs/08` draws it.** Rejected
above; the deviation from the sketch is deliberate and is why this section
exists.

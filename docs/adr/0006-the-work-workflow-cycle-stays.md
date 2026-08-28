# ADR 0006 — The `Work ↔ Workflow` cycle stays; what could honestly be removed from it was

- **Status:** accepted
- **Date:** 2026-08-28
- **Phase:** 5 (Collaboration & Time)
- **Relates to:** `docs/04-module-structure.md` §3, ADR 0002

## Context

ADR 0002 recorded the cost of removing the `ApprovalStateReader` port: `Work →
Workflow` and `Workflow → Work` both appear in `deptrac.yaml`, so the module
graph is no longer a tree. Deptrac reports no violation because both edges are
declared, but it is a cycle, and the comment above the `Workflow` layer in that
file no longer describes the code.

Phase 5 revisited it with the intent of breaking it. What follows is what was
found, because the next person to see the cycle will propose exactly the same
fix and deserves to start from the result rather than from the idea.

`Workflow → Work` is the intended direction and is not the problem: every import
is a listener or a rule action — Workflow subscribing to Work's events and
acting on them, which is what `docs/04` §3 describes. The cycle is created
entirely by `Work → Workflow`, which was six import sites in four files.

## Decision

**Two of the three causes were removed. The third was not, and the cycle
stays.**

Removed, because both were wrong independently of the cycle:

1. **The state-category vocabulary moved to `Platform\Domain\Work\StateCategory`.**
   `CATEGORIES`, `CLOSED_CATEGORIES` and `COMMITTED_CATEGORIES` were constants on
   `WorkflowStateModel`, so asking "is this work finished" required importing the
   workflow engine. Lists, boards, search, the calendar and the workload
   calculation all ask it, and none of them have any business knowing a workflow
   engine exists. `WorkflowStateModel` keeps the names as aliases, because a
   state is still the thing that carries a category and that is where a reader
   looks.

2. **The `state` relation is declared by `WorkflowServiceProvider`**, through
   `resolveRelationUsing`, rather than by `WorkItemModel`. The state is
   Workflow's; the same arrangement already attaches Organization's
   `employeeProfile` to Identity's membership for the same reason. Larastan
   cannot see a relation registered at boot, so `phpstan.neon` carries the
   matching exception — next to the identical one for `employeeProfile`.

Kept: `WorkItemService` imports `TransitionService`, `WorkflowModel` and
`WorkflowStateModel` in the create and transition paths.

## Consequences

**Bought:** four of the six import sites are gone, and both changes are
improvements on their own terms — shared vocabulary sits below both modules, and
a relation is declared by the module that owns it.

**Paid:** the cycle remains, and the deptrac comment ADR 0002 called inaccurate
is still inaccurate. `Work` still lists `Workflow`.

**Trigger to revisit:** a second engine — a rules or state machine
implementation that Work must choose between at runtime. At that point Work has
a real reason not to name a concrete one, and the interface would express
something rather than hide something.

## Alternatives considered

**A `WorkflowGateway` contract in Platform, implemented by Workflow.** Designed,
then rejected on reading it back. The interface came out as
`assertLegal($workflowId, $fromStateId, $toStateId, $facts)` — Work still
thinking entirely in Workflow's terms, one indirection later. The coupling does
not go away; it is laundered until deptrac stops naming it, and the transition
path — the busiest code in the product — becomes harder to follow in exchange
for a tidier graph.

The distinction that decided it: `StateCategory` is expressed in Work's own
language ("is this finished"), so moving it clarified something. A gateway
parameterised by workflow ids is expressed in Workflow's language, so hiding it
behind an interface would only have concealed that Work uses the workflow
engine — which it does, deliberately, and which the reader is better off seeing.

**Merging Workflow into Work.** Removes the cycle by removing the boundary.
Rejected: the boundary earns its place — the rule engine, the transition graph
and the recurrence materialiser are a large, separately-testable subsystem, and
`docs/01` §5 names keeping them out of Work as what stops a status change from
becoming a 900-line service.

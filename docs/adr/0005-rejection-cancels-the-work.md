# ADR 0005 — A rejected approval cancels the work it was about

- **Status:** accepted
- **Date:** 2026-08-28
- **Phase:** 5 (Collaboration & Time)
- **Relates to:** `docs/02-domain-model.md` §6, §7, `docs/08-ux-navigation.md` §7

## Context

A reviewer has three decisions: approve, request changes, reject.
`TransitionOnApprovalDecision` mapped the first two onto workflow states and
deliberately left the third unmapped, with a comment saying the meaning of a
rejection was a policy question nobody had answered.

Leaving it unanswered was not neutral. The approval resolved as `rejected` and
the work item stayed in **In Review** — and the only edges out of In Review are
guarded to reviewers. The person whose work was rejected could neither resume it
nor close it, and the board showed it sitting in review that had already
finished. The approval said one thing and the board said another, and the board
is what people act on.

The second-order effect is worse than the stuck item: a reviewer who finds that
"reject" strands the work learns to use "request changes" for everything,
including for work that should simply stop. The vocabulary collapses to two
words, and the one that meant "stop" stops being said.

## Decision

`rejected` lands the work item on the `cancelled` state.

Rejecting means the work should not continue. Requesting changes means the same
work should continue, differently. Two decisions, two meanings — rather than one
meaning and a spare button.

The move is expressed as an edge in the workflow graph, `In Review → Cancelled`,
guarded to this item's reviewers and requiring a comment. Not as a special case
in the listener, because `docs/02` §7 puts the legal moves in the graph and the
UI reads the graph: a transition the graph does not contain is a transition the
product cannot explain.

It is a separate edge from the existing `ANYWHERE → Cancelled`, which is guarded
by `work_item.delete` — a permission neither the Manager nor the Employee role
holds, and therefore unusable by the very reviewer the product just asked to
decide. Cancelling *from review* is a reviewer's act; cancelling from anywhere
else remains an owner's.

## Consequences

**Bought:** the three decisions are three outcomes. Rejected work leaves the
review queue, leaves the assignee's active list, and is visible as cancelled
rather than as perpetually-in-review.

**Paid:** a rejection is now destructive in the sense that matters to a person —
their work is closed, by someone else, in one click. Two things make that
acceptable and both are already in place: the edge requires a comment, and
`Cancelled` is not deletion — the item, its history, its comments and its logged
time all remain, and `work_item.transition_any` can reopen it.

**Trigger to revisit:** an organization whose review step gates something other
than "should this work happen" — a compliance sign-off, say, where a rejection
means "not yet" rather than "not at all". The workflow graph is per-organization
data, so that case is answered by editing their edge rather than by changing
this default.

## Alternatives considered

**`rejected` → `in_progress`, identical to a changes request.** Honest about how
reviewers often behave. Rejected because it makes the two decisions
indistinguishable in effect, and a button whose outcome equals another button's
should be removed rather than kept as decoration.

**Leave the item in review and add an edge the author may take.** Preserves the
most freedom and asks the requester to decide what happens next. Rejected as the
default because it adds a state the product then has to explain — "rejected, and
still in review" — to answer a question the reviewer has usually already
answered. An organization that wants it can add the edge; the graph is theirs.

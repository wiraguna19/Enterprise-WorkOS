# ADR 0013 — A mention notifies through the dispatcher, like a workflow rule does

- **Status:** accepted
- **Date:** 2026-09-08
- **Phase:** 5 (Collaboration)
- **Relates to:** `docs/04-module-structure.md` §3 (the dependency graph),
  `docs/11-testing-strategy.md` §4 flow 6

## Context

`@mentions` notify nobody, and never have.

`CommentService::recordMentions()` resolves names against active memberships and
writes `mentions` rows. Nothing reads them. There is no event, no listener, and
no code path anywhere that produces a `comment.mentioned` notification — a type
`NotificationResource` has a sentence for, and which the settings screen offers
a preference toggle for under a *different* key (`work.mentioned`). **A write
path with no read path**, which is the shape the activity log rotted in for
three phases (docs/11 §7), sitting under one of the fifteen flows: docs/11 §4
flow 6 is "comment with a @mention, attach a file", and only the attaching half
does anything.

`docs/04` §3 draws `Collaboration` and `Notification` as siblings, so the
obvious shape — a subscriber in Notification listening to a Collaboration event
— is a sideways import Deptrac refuses.

The first decision taken here was to let Notification read its siblings, on the
grounds that it is a pure observer imported by nobody. **That premise was
false.** `Workflow` imports `NotificationDispatcher` directly, in `NotifyAction`
and `EscalateAction`. Adding `Collaboration` to Notification would therefore
have closed a real cycle:

```text
Work ──► Workflow ──► Notification ──► Collaboration ──► Work
```

Checking the config before writing the ADR is what caught it. The lesson is
worth more than the decision: **an architectural claim about a graph is
checkable, and "X is imported by nobody" is exactly the kind of statement that
is believed rather than verified.**

## Decision

**A module that wants to notify calls `NotificationDispatcher` directly.**
`Collaboration` gains `Notification` as an allowed dependency, and
`CommentService` dispatches `comment.mentioned` to the memberships it just
resolved.

This is not a new pattern. It is exactly what `Workflow` already does, and
following it costs one line of Deptrac config instead of a new direction of
travel. No cycle is created: nothing Notification depends on reaches
Collaboration.

The rejected alternative — a `UserMentioned` event in `Platform` — keeps the
module graph untouched and puts a Collaboration concept in the shared kernel.
The first cross-sibling event to move there sets the precedent for every one
after it, and Platform becomes the place modules keep things they need to say
to each other.

## Consequences

- Mentions go through the same dispatcher as every other type, so they inherit
  the preference check, the ALWAYS_IN_APP list, the dedupe key and the actor
  suppression rule — rather than a second delivery path written for one
  feature, which is how two notification systems start.
- **Comments now depend on notifications.** Collaboration cannot ship without
  Notification present. That coupling is real and is the price of this
  decision; Workflow has been paying it since Phase 3.
- The settings screen offered `work.mentioned` while the product speaks
  `comment.mentioned`. A preference for a type nothing sends cannot fail
  visibly, which is why it survived — the same reason that whole screen saved
  nothing until `0009161`.
- **The permitted graph already contains a cycle** — `Notification → Work →
  Workflow → Notification` — which `docs/04` §3 forbids in prose ("No cycles.
  Enforced by Deptrac in CI") and the config allows. This ADR does not fix
  that, and does not add to it. It is recorded here because it was found here,
  and because the enforcement everyone believes is in place is not.

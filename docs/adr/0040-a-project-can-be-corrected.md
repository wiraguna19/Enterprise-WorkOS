# ADR 0040 — A project can be corrected

- **Status:** accepted
- **Date:** 2026-09-23
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/03` §8, `docs/06` §2, ADR 0018, ADR 0039

## Context

`POST /projects` creates one. Nothing else existed. There was no
`PATCH /projects/{key}`, no archive, no member management — no route, and no
controller method to route to.

So a typo in a project's name was permanent, its dates could not move, a
project that finished could not be taken off the boards, and a project created
as **private** was visible to its creator and to nobody else, for ever, because
`project_members` had no write path either.

Four permissions have been seeded since Phase 1 and granted to roles:
`project.update`, `project.archive`, `project.delete`,
`project.manage_members`. `ProjectPolicy` answers all four, and has since Phase
5.

**That policy is what hid this.** `EveryPermissionMeansSomethingTest` asks
whether a permission is *consulted*, and a policy method consults it — so four
permissions with nothing behind them had an alibi. It is the same shape as the
write that hid behind the read on its own path, one layer up: the guard asked a
question the defect could answer.

## Decision

**`PATCH /projects/{key}`, through a `ProjectService`.** The transaction
boundary is a service, not the controller, because that is what docs/01 §3 says
and what `WorkItemService` already does. Only changed fields are written and
only those are logged: a save that reports every field as touched turns the
history into noise nobody reads.

**`lock_version` travels with the edit**, and a 409 offers no force. Version 0
is a real version, so the controller distinguishes "absent" from "zero" —
folding them together is how optimistic locking is silently off for the first
edit of everything the product creates (docs/03 §8).

**The key is refused BY NAME, not dropped.** `ENG` is in every work item
reference the project has ever produced (docs/08 §2), so changing it is a
migration and not an edit. A field silently dropped from a PATCH is a form that
appears to work until it reloads — so the API says `prohibited` with a sentence,
and the screen does not render a control for it at all.

**Archiving is `archived_at`, not a status, and not a DELETE.** `status` says
how the work is going — planning, active, on hold — and "archived" is not one of
those answers; conflating them would make a project resumed from hold come back
as whatever it was archived as. It is reversible, nothing is deleted, and the
control says so, because a control is named for what it DOES.

**The route is guarded on `project.view`; the POLICY decides.** Guarding it on
`project.update` would overrule `ProjectPolicy`, which has said since it was
written that a project's OWNER may correct their own project without holding the
organization-wide permission. Putting the coarse check in front is docs/06 §2's
named failure: two layers answering the same question, with the coarse one
silently winning.

## Consequences

- A project can be renamed, re-scoped, re-dated, put on hold, archived and
  brought back — by its owner, or by somebody holding the permission.
- **Members are still unwritable**, so `visibility: private` remains a one-way
  door: the create form offers it, the creator is added as the only member, and
  nobody can be added afterwards. This is the next slice and it is named here so
  it is a bill rather than a gap. `project.manage_members` is still a permission
  with a policy and no endpoint.
- `project.delete` likewise has no endpoint, and deliberately: a project carries
  work items, time entries and history, and archiving is the honest way to make
  one stop appearing. If deletion ever ships it needs its own decision about
  what happens to all of that, not a route.
- **The guard needs a sharper question.** "Is this permission consulted?" is
  satisfied by a policy. The question that would have found this is "does any
  ROUTE reach a policy method that consults it" — noted here rather than built,
  because a guard is only worth adding when it can be written without producing
  false names, and this project has already learned that over-asking is how a
  list stops being read.

# ADR 0004 — Work that is not in a project is private to the people involved in it

- **Status:** accepted
- **Date:** 2026-08-28
- **Phase:** 5 (Collaboration & Time)
- **Relates to:** `docs/06-auth-and-authorization.md` §2, `docs/02-domain-model.md` §4

## Context

`WorkItemVisibility` answers "may this person see this work item" with five
clauses. The first reaches the work through its project; the other four reach it
through the person — assigned now or previously, created by them, watched by
them, or assigned to someone in their reporting line.

A work item's `project_id` is nullable, so an item can exist that no project
clause can reach. For such an item, clauses 2–5 are the only route. The
consequence is easy to state and easy to read as a bug: **an Organization Admin
sees every work item in the tenant except project-less items belonging to other
people.** `project.view_all` widens clause 1, and clause 1 needs a project.

This was noticed in Phase 5 while auditing visibility for search and the
calendar, both of which reach across every dated or indexed record in the tenant
and would have carried the same gap outward.

## Decision

The behaviour stands, and is now intended rather than incidental.

Work with no project is private to the people involved in it. Creating an item
without a project is how a person keeps a note to themselves inside the product;
putting it in a project is the act of sharing it. Nobody — including an
Organization Admin — reaches it through a permission alone.

Two things follow, and both are part of this decision rather than optional
polish:

1. A test asserts it, so the next person to widen visibility has to decide to
   break it rather than break it by accident.
2. The work item creation UI must say so, at the point where the project field
   is left empty. A privacy rule the user cannot see is a privacy rule they
   will violate by accident — in both directions: someone shares a note they
   meant to keep, or keeps one they meant to share and wonders why nobody
   replied.

   That UI does not exist yet — work items are created through the API and the
   seed as of Phase 5 — so this is an obligation on whoever builds it, recorded
   here because by then the reason will not be obvious from the code. The rule
   itself is enforced today, and tested.

## Consequences

**Bought:** a place inside the product for work that is not yet anyone else's
business — a draft, a personal reminder, a task someone is not ready to
announce. Without it, the honest workaround is a notes app, and work leaves the
system that is supposed to hold it.

**Paid:** an Organization Admin cannot answer "show me everything in this
tenant" from the product. For an audit or a legal hold, the answer is the
database and an audited platform-mode crossing (`TenantContext::runAsPlatform`),
not a UI affordance — which is the correct shape for that request anyway,
because it leaves a record that it was made.

Also paid: the admin's mental model is now two rules rather than one. This is
what point 2 above pays for.

**Trigger to revisit:** a customer with a retention or discovery obligation that
covers all work in the tenant. At that point the answer is an explicit,
audit-logged export — not a sixth visibility clause, because a clause makes the
access silent and continuous, and the obligation is satisfied by an access that
is neither.

## Alternatives considered

**A sixth clause behind a new `work_item.view_all` permission.** Rejected on
what it means rather than on cost: it makes every private note readable by
whoever holds the permission, silently and continuously, and the people writing
those notes have no way to know. If an organization wants that, it wants an
export with an audit trail, not a widened read path.

**Forbidding project-less work — `project_id NOT NULL`.** Removes the question
entirely and is enforceable in the schema. Rejected because it removes the
capability, not just the ambiguity: the alternative for the user is not "put it
in a project", it is "write it down somewhere else".

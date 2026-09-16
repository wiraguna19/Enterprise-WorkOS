# ADR 0022 — Erasure is anonymisation, and the evidence is out of its reach

- **Status:** accepted
- **Date:** 2026-09-16
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §1, `docs/10` Phase 7, ADR 0019, ADR 0021

## Context

ADR 0021 closed the retention half of `docs/10`'s Security column and said in
its own consequences what it could not answer: **"delete this person's data"
cannot be answered by dropping a month.** Retention is about age; erasure is
about a subject, and the two share no mechanism.

Nothing in this product could remove a person. `person.deactivate` has been
granted to two roles since Phase 1, `MembershipPolicy::deactivate()` has existed
for as long, and no route has ever reached either.

That absence is worth a sentence, because
`EveryPermissionMeansSomethingTest` did not catch it: the test asks whether any
policy, route or service consults a permission, and a policy method that nothing
routes to satisfies it. **A permission consulted only by unreachable code passes
that test and still means nothing.** The bill is narrower than it looks.

## Decision

**Erasure is anonymisation. Nothing is deleted.** A hard delete would cascade
through work items, comments, transitions and approvals — it would not delete a
person, it would delete a year of the organization's history and silently change
every report computed from it. What a subject is owed is that the data stops
being about an identifiable person, and that is what this does. The endpoint is
therefore `POST …/erase`, not `DELETE …`: the honest verb for what happens.

**The snapshots are the substance of the work.** `activity_logs
.actor_name_snapshot`, `audit_logs.actor_email_snapshot`, and the
`payload.actor_name` inside other people's notifications were all added
deliberately so history would survive somebody leaving — which is precisely what
makes them the last hiding places of a name after an erasure that only touched
`users`. An erasure that skips them is theatre. The same goes for the invitation
row, which holds the one copy of the address outside `users`.

**The two logs are not touched, because the database will not allow it — and
that is the right answer.** `activity_logs` and `audit_logs` have carried a
`BEFORE UPDATE OR DELETE` trigger since Phase 1: append-only enforced by the
schema rather than by application discipline, on the grounds that evidence the
application can rewrite is not evidence.

This ADR was first written the other way round. Erasure redacted both tables in
place and the ADR argued the exception was narrow enough to be safe. Postgres
refused it — `insufficient_privilege`, from a trigger written seven phases
earlier — and the refusal was better reasoning than the ADR's. **A guarantee
that bends for a good reason is not a guarantee**, and "honouring an erasure" is
exactly the good reason somebody would offer for editing an audit trail.

So the copies in those two tables are not redacted. They EXPIRE, on the
retention windows ADR 0021 set the week before: twelve months for activity,
twenty-four for audit. That is a real gap, bounded and dated, and the two ADRs
lock together — retention is what makes erasure's reach acceptable, and erasure
is what makes retention more than housekeeping.

**What the product owes instead is to say so**, and the erase screen does: the
name stays in the append-only records until it ages out. An interface claiming a
clean sweep would be the actual failure here.

**The erasure records itself.** Afterwards there is nothing in the rows to say it
happened: a user called "Deleted person" with no address is indistinguishable
from a broken import. `memberships.erased_at`, `users.erased_at` and a
`person.erased` audit event are that record. The event names the administrator
and the membership and **never the address** — quoting it would put back exactly
what the erasure just removed.

**An account shared with another organization is refused.** Anonymising `users`
would reach into a tenant whose administrator never asked and may be legally
obliged to keep the record. Revoking access here while leaving the name visible
would be worse than refusing: it would report "erased" over somebody still named
on every comment they wrote. So it is refused by name, `shared_account`, and
what is owed is written down below rather than half-done.

**Gated on `person.deactivate`, not a new key.** Erasure is what revoking access
means when it is permanent; the same person signs off on both. A fifth
permission would have arrived with nothing else consulting it.

**Typing the name is required in the interface.** This is the only irreversible
act in the product and the only one with no undo to offer afterwards.

## Consequences

- `person.deactivate` and `MembershipPolicy::deactivate()`'s sibling finally have
  a route. Ordinary deactivation — revoking access without erasing — still has
  none, and is now the smaller gap of the two.
- **Cross-organization erasure is owed.** A person in two tenants can be erased
  from neither through this endpoint. Honouring it needs a platform-level
  workflow, a platform administrator to run it, and an answer to what happens
  when one tenant is obliged to keep what the other must delete.
- Sessions are DELETED here, unlike everywhere else in this product, where a
  revoked session row is kept so somebody can be told why they were logged out.
  There is nobody left to tell, and the row holds an IP address and a user
  agent.
- The employee profile is emptied but the row stays, because other people's
  profiles point at it as their manager. A reporting line that lost its node
  would break the org chart of everybody below it.
- Comment BODIES are untouched. What somebody wrote in a work item is the
  organization's record of a decision, not personal data about its author; text
  they wrote about themselves inside one is beyond what this can find, and
  pretending otherwise would be the worse lie.
- An erased person keeps their membership id, so every foreign key still
  resolves and the work they did still has an author — an anonymous one. That is
  the whole trade this ADR makes.
- **An erased person is refused every grant and every denial.** The first
  version left that out, and opening the screen found it in a minute: the
  product announced somebody as erased and went on offering a form to make them
  Organization Admin. The controls are gone from the profile and the refusal is
  in the service, because a control that survives the state it was written for
  is the defect, not the button. The address is now sent as null for the same
  reason — `users.email` holds a random placeholder at a reserved domain, and
  the profile was rendering it as a `mailto:` link.
- A test asserts that an UPDATE on `audit_logs` still throws. It is not testing
  Postgres; it is testing that nobody has quietly disabled the trigger to make
  an erasure look tidier. If that test ever passes silently, an erasure has been
  given the power to edit the evidence.
- The retention windows are now load-bearing for a compliance claim, not just
  for disk. Lengthening `audit_logs` to ten years lengthens how long an erased
  person's address survives, and that trade should be made deliberately.

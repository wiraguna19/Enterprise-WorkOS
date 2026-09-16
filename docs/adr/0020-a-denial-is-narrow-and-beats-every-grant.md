# ADR 0020 — A denial is narrow, carries a reason, and beats every grant

- **Status:** accepted
- **Date:** 2026-09-16
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §2, ADR 0016, ADR 0018

## Context

Every authorization decision in this product has been additive since Phase 1.
A person's permissions are the union of what their roles carry — org-wide from
`membership_roles`, on one thing from `scoped_role_assignments` (ADR 0016) —
and the only way to stop somebody doing one thing has been to take away a role
that carries twenty others.

That is not a theoretical gap. "Ahmad manages Frontend, but not while Frontend
reorganizes" has one answer in an additive model: demote Ahmad. So the real
answer, in every product without a subtractive rule, is that nobody does
anything and the exception is handled by asking people not to — which is a
permission model that lives in a chat thread.

## Decision

**Deny wins over everything.** A denial is not a lower-priority grant, a
tiebreak, or a level comparison. `PermissionResolver` unions the grants and then
subtracts, so a role granted after the denial does not restore the permission,
and no ordering of writes changes the answer. Anything subtler is a rule
somebody has to hold in their head while reading two tables.

**Per membership, never per role.** A denial attaches to one person. Putting it
on a role would make "Manager, except…" a second kind of role definition living
beside `role_permissions`, and ADR 0018 already decided that what a role
contains is one list in one place.

**Scoped or everywhere, and the scope is optional** — the mirror image of a
grant, where the scope is REQUIRED. A grant with no scope makes somebody an
administrator of everything, so it should not be reachable by leaving a dropdown
alone; a denial with no scope takes something away everywhere, which is the safe
direction to arrive at by accident.

**The scoped check runs BEFORE the org-wide answer.** `hasOnScope()` answers
"do they hold it everywhere" first, as a cache lookup, and returning that answer
before consulting the denials would mean a scoped denial never stopped the
manager it was written for. That ordering is the whole slice; the rest is
plumbing.

**A reason is required, and it is the only required free text in this module.**
A denial outlives the incident that caused it. An entry with no reason is a
mystery to whoever reads it six months later — including whoever wrote it — and
the only safe thing to do with a mystery in an authorization table is to leave
it in place forever.

**There is no lockout guard, on purpose.** The obvious one — "refuse a denial of
`role.manage` that would leave nobody able to lift it" — cannot fire.
`MembershipPolicy::manageRoles()` refuses a denial aimed at yourself, and the
actor holds `role.manage` org-wide, so at the moment any denial is written there
is always at least one other person who can lift it. This codebase keeps a test
whose whole job is finding code nothing reaches; adding some deliberately would
be an odd way to spend that.

**"Why can't I do that" is an endpoint.** `GET
/people/{membership}/permissions/explain?permission=…` names the roles that
grant it, the scopes it is granted on, and the denials that beat them, with
their reasons. Without it a refusal is a wall with no sign on it: neither the
person hitting it nor the administrator they ask can tell a permission never
granted from one taken away. The permission is a query parameter because a key
contains a dot, and a dotted last path segment is a filename to half the proxies
in the world.

**Denials appear beside the grants, not on a screen of their own.** "What may
this person do" is one question, and an interface that showed the grants and hid
the denials would answer it wrongly in the most confident way available — with a
list that looks complete.

## Consequences

- `permission_denials` stores the permission as TEXT with no foreign key, so a
  denial survives a permission being renamed — which means a typo would store a
  row that subtracts nothing and reads on screen as a perfectly good denial.
  The service checks the key exists and refuses `unknown_permission`, the same
  shape ADR 0016 uses for `scope_not_found`.
- The uniqueness index has to use `COALESCE`: in Postgres a null is not equal to
  itself, so `(membership, permission, null, null)` could otherwise be inserted
  a hundred times.
- **Denials subtract in SQL, not in PHP.** The first version read them in a
  statement of its own and diffed the two lists, which is the same answer and
  one extra round trip on every uncached permission resolution. Six query
  budgets in `QueryPerformanceTest` failed within the hour — every one of them
  sitting exactly on its limit, which is what a budget written as `< 15` is for.
  Both resolution queries now carry a `NOT EXISTS`, and the only denial read
  left on its own is the one `hasOnScope()` must do before the org-wide cache.
- Every write goes through the same `settle()` as a grant — version the
  permission cache, write an activity record — because a denial that took
  fifteen minutes to apply reads as a denial that did nothing.
- No new permission arrives with this. Denials are administered by whoever
  administers roles: `role.manage` for the writes, `role.view` for the reader.
  A fifth key would have landed straight on the bill in
  `EveryPermissionMeansSomethingTest`.
- **A denial is invisible to anybody without `role.view`.** The reasons are
  written by administrators about people — "conflict of interest while Frontend
  reorganizes" is not a sentence a colleague should be able to fetch — so the
  explainer is gated exactly as the roles list is, and a person cannot currently
  ask why THEY were refused. Written down rather than papered over.

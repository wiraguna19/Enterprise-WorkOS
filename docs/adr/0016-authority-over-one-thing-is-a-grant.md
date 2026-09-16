# ADR 0016 — Authority over one thing is a grant, and the coarse gate makes way for it

- **Status:** accepted
- **Date:** 2026-09-16
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06-auth-and-authorization.md` §2 (the four layers),
  `docs/12-risks-tradeoffs.md` §10, ADR 0015

## Context

`scoped_role_assignments` and `PermissionResolver::hasOnScope()` have been in
this codebase since Phase 1, described in the migration as "unused at MVP by
design — it exists now so that Phase 7 is a feature, not a migration of live
permission data". Nothing ever wrote a row.

Two policies recorded the consequence in their own docblocks rather than
working around it. `TeamPolicy` would not let a team's lead manage their own
team; `DepartmentPolicy` would not let a department's head rename their own
department. Both refused for the same stated reason: `if (lead)` is the
hardcoded role check `docs/06` §2 rules out by name, and hardcoding it would be
the thing that had to be torn out when the real mechanism arrived.

This is that arrival. And writing it exposed the part nobody had thought
through: **the route's `permission:` gate cannot ask a scoped question.**

## Decision

**A grant is always scoped.** `POST /people/{membership}/roles` requires
`scope_type` (project, team or department) and `scope_id`. Making somebody an
administrator of the whole organization is a different act with a different
blast radius, and it is not reachable by leaving a dropdown alone.

**The coarse gate is removed from the four routes whose policy now asks a
scoped question** — `PATCH /departments/{id}`, `POST /departments/{id}/move`,
and the two team-member routes. `permission:team.manage_members` can only ask
"do you hold this across the organization", so in front of a scoped policy it
refuses the grant before the policy is ever consulted: the weaker layer wins,
the feature silently does not exist, and **every test of the policy passes.**
That is this codebase's two-layers-disagree defect (three instances, see
`layered_authorization`) in its most confusing form.

Those routes are not ungated: `auth:sanctum` and the tenant resolver still run,
and `ScopedRoleTest` asserts that an employee with no grant still gets 403 —
the policy is stricter than the gate it replaced.

**Four refusals**, each 409 with a stable `details.refusal`:

| refusal | why |
|---|---|
| `already_organization_wide` | The grant would change nothing today, and would quietly become load-bearing the day the org-wide role is revoked. |
| `scope_not_found` | There is no foreign key on `(scope_type, scope_id)` — it is polymorphic — so a typo'd id stores a grant that resolves to nothing and reads on screen as a good one. |
| `unknown_role` | Roles are rows; a key that is not one is a typo, not a new role. |
| `grant_not_found` | Revoking is scoped to the membership in the path, so one person's grant cannot be revoked under an authorization decision made about another. |

**Nobody may grant to themselves.** `MembershipPolicy::manageRoles` refuses
self for the reason `deactivate` does: an administrator who can widen their own
authority without a second pair of eyes is the shape of an escalation, and
another administrator can always do it for them.

**Every write bumps the permission cache version and records an activity
event.** The cache is versioned rather than deleted — a grant that took fifteen
minutes to apply reads as a grant that did nothing — and `membership_roles`
records `granted_at` and nothing about who granted it, while "who may do what,
and since when" is the first question asked after an incident.

## Consequences

- **A team lead is still not special.** Leading a team grants nothing; a ROW
  does, with a grantor, a timestamp and an audit record. The customer can see
  it, change it and revoke it, which an `if (lead)` buried in a policy never
  offered.
- `DepartmentPolicy::delete` stays unscoped. A grant ON a department is
  authority over what happens inside it, and erasing the department is not
  something that happens inside it.
- **Scoping `org_admin` to one department is how "head of Engineering" is
  expressed**, because roles are rows and this organization has four. A
  customer who wants something narrower creates a role; the custom role builder
  in `docs/10` Phase 7 is what makes that pleasant, and this works without it.
- `GET /roles` exists so the grant form offers what the organization HAS. A
  list of the four seeded keys in the interface would be the fifth copy of a
  vocabulary in this codebase, and the one that cannot grant a custom role.
- `person.invite` still has no endpoint. Flow 2 is "create department → team →
  invite person → assign role", and this pays off the last of those four. The
  flow still asserts the invite absence, so it fails the day somebody builds it
  without finishing the flow.

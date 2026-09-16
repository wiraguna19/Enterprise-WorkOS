# ADR 0018 — A role cannot contain authority its author does not have

- **Status:** accepted
- **Date:** 2026-09-16
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06-auth-and-authorization.md` §2, ADR 0016

## Context

ADR 0016 shipped scoped grants and recorded the gap they left: this product has
four roles, so "head of Engineering" had to be spelled as `org_admin` scoped to
a department — a grant that drags every other permission in the catalogue into
that department with it. The answer is a role containing exactly what somebody
means, which `docs/10` Phase 7 calls the custom role builder.

Writing one raises a question the seeded roles never did: **what stops
`role.manage` from becoming every other permission?** An administrator who can
compose a role from the whole catalogue can write one containing `export.run`,
`person.deactivate`, anything — and grant it. Granting to yourself is already
refused (ADR 0016), so the escalation is exactly one colleague long.

## Decision

**A role may only contain permissions its author holds**, checked against their
EFFECTIVE set at the moment they save. `beyond_your_own_authority`, 409, naming
the permissions it refused.

This is the whole security story of the feature. It costs something real — an
administrator without `export.run` cannot build an "Analyst" role that includes
it, and must be granted it first — and that cost is the right shape: authority
is delegated downward, never conjured sideways.

The check reads the resolver's effective set rather than the author's roles, so
somebody holding a permission through two roles still holds it, and somebody
whose role was narrowed a minute ago cannot spend authority they no longer have.

**The four roles the product ships with are not editable or removable.**
`is_system`, refused with `system_role`. Every permission test, the seed,
`docs/06` and the demo data assume `org_admin` means what it says; a customer
who removes `organization.view` from it locks their organization out of its own
settings with no way back in through the product. The screen shows them and
says why rather than rendering disabled checkboxes.

**A role people hold cannot be deleted** (`role_in_use`). The foreign key would
cascade the grants away and quietly reduce what those people can do — noticed a
week later as "I used to be able to do this", which is the least debuggable
kind of report.

**Changing a role's permissions bumps the cache version of every holder.** The
permission cache is versioned per MEMBERSHIP, so there is no single key to drop.
A role whose holders were not bumped is a permission change that applies in
fifteen minutes, to some people, depending on when they last made a request —
and the test asserts the new set applies on the very next call.

**Roles are addressed by key, not id.** The key is unique per organization, it
is what a grant names, and it is what an audit log records; a uuid in the URL
would make the address bar and the audit trail disagree about what a role is
called.

## Consequences

- A custom role is grantable and scopable like any other, so "head of
  Engineering" is now a role with three permissions rather than `org_admin`
  pointed at a department.
- `GET /permissions` serves the catalogue. Permissions are the PRODUCT's
  vocabulary, not a customer's, so the list is global and identical for
  everybody — and served rather than written into the interface, because a form
  offering a permission this build does not implement writes a role that grants
  nothing while reading as though it grants something.
- A new role is always created at `level` 0 and `is_system` false. Level orders
  roles for display and for "who outranks whom"; a custom role claiming to
  outrank `org_admin` would be a lie told by a dropdown.
- `PATCH` without a `permissions` key leaves the set alone; sending `[]` empties
  it. A form that could not say "no change" would rewrite the set on every
  rename.
- **Explicit deny is still not built.** Every permission in this product is a
  grant, and a role that takes something away has no representation — the
  resolver unions and never subtracts. That is the next decision in this column,
  and nothing here presumes its shape.

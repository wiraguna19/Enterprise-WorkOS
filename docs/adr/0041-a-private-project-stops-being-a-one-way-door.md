# ADR 0041 — A private project stops being a one-way door

- **Status:** accepted
- **Date:** 2026-09-23
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/03` §2, `docs/06` §2, ADR 0040

## Context

`project_members` has decided who can see a project since Phase 2.
`ProjectModel::scopeVisibleTo` reads it on every list request, and
`ProjectPolicy` reads it again to decide who may change a project without
holding the organization-wide permission.

**It had no write path.** `POST /projects` inserts the creator as the owner —
with a comment saying "creating a project you cannot then see is the kind of bug
that only shows up in production" — and that was the only row anything ever
wrote. There was no endpoint to add a second person, and none to remove one.

So the create form's **private** option was a setting the product could not
complete: the project became visible to its creator and to nobody else, for
ever. `project.manage_members` was seeded in Phase 1, granted to roles, and
answered by a policy method that nothing called — the same alibi ADR 0040
describes.

## Decision

**A row grants access to a person OR a team, never both** — which the database
already enforces with a CHECK, and which the service now also refuses in a
sentence. Both say it because they say it to different audiences: the constraint
makes it true of every row whatever writes it, the refusal is something the
person can act on.

**Team access is not a copy of a roster.** A row naming a team follows that team
as people join and leave it. A member list assembled by hand from a team goes
stale the first time somebody moves, and then quietly disagrees with the team it
was copied from.

**A project keeps at least one owner**, and both routes to breaking that —
removing the last owner and demoting them — are refused by name. The owner row
is what lets the policy say yes to somebody without the organization-wide
permission, so a project with no owner is editable only by an administrator, and
a private one is visible to nobody at all.

**Removing is `removed_at`, never a DELETE.** Who had access to a project and
when is exactly the question an audit asks later, and a deleted row answers it
with silence.

**Guarded on `project.view`, with the POLICY deciding** — the same choice as ADR
0040, for the same reason: a project's owner manages their own project's access
without holding `project.manage_members` organization-wide, and putting the
coarse check in front would overrule the policy that has said so since it was
written.

**The screen offers two pickers and a switch**, not one list mixing people and
teams. A single picker would have to encode which kind each option is, and the
first thing to read that encoding wrongly sends a team id as a membership id.

## Consequences

- `visibility: private` is a usable setting for the first time. The test that
  proves it is the one that matters: a project 404s for somebody, they are
  added, and it does not.
- Removed members are retained and invisible to the list, which asks about now.
  Nothing yet reads the history those rows keep — that is a read path owed to a
  write path, and it is named here so it is a bill rather than a gap.
- `project.delete` is still the one project permission with a policy and no
  endpoint, deliberately (ADR 0040).
- Adding somebody to a project does not notify them. Every other grant in this
  product is equally silent, so this is consistent rather than correct; when
  notifications learn about access, this is one of the events they should carry.

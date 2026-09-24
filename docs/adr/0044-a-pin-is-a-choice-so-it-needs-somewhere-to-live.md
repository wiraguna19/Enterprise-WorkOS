# ADR 0044 — A pin is a choice, so it needs somewhere to live

- **Status:** accepted
- **Date:** 2026-09-24
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/08` §1, ADR 0040, ADR 0041

## Context

docs/08 §1 draws a `PROJECTS` section in the sidebar, above `TEAMS`, and states
the rule in prose: *"Projects and Teams are pinned lists, not full trees. Users
pin the 3–7 they actually work in; 'Browse all' opens a searchable directory. A
sidebar that lists 200 projects is a sidebar nobody reads."*

**Only Teams was built.** The Projects half has been a drawing since Phase 1.

## Decision

### A table, not a derived list

The cheap version is "show the projects this person can see, capped at six".
For an employee that is nearly right, because project visibility follows
membership. For anybody holding `project.view_all` it is **every internal
project**, so the six shown are whichever six sort first — and those are exactly
the people with the most projects. That is docs/08's own warning with a smaller
number in it.

A pin is a choice. A choice needs somewhere to live, so `pinned_projects` exists.

### Per membership, not per user

Somebody in two organizations pins different things in each, the row dies with
the membership rather than outliving it in an organization they have left, and
the table is tenant-scoped like everything else here.

### A pin is not a grant

The pinned list is read through the same visibility scope as every other
project read. Access can be taken away long after a pin was made (ADR 0041), and
a sidebar entry that 404s is worse than no entry. There is a test for exactly
this, because it is the failure that would not be noticed until somebody clicked
it.

### A pin is not a status either

An archived project is filtered out of the sidebar and **keeps its pin**.
Archiving is reversible (ADR 0040); dropping the pin would quietly punish
somebody for putting a project away for a month, and they would have to
rediscover their own list when it came back.

### Idempotent, decided by the database

`POST /projects/{key}/pin` with `pinned: true` twice is not an error — the
person wanted it pinned and it is. A unique index makes that true whatever
calls it, rather than depending on a caller checking first.

### The control lives in the directory

"Pin the 3–7 you work in" is a decision somebody takes while looking at the
whole list, not one they navigate into a project to make. It is a real
`aria-pressed` button whose label names the direction of the press, because
"Pin" on an already-pinned row is the control that gets pressed by mistake.

## Consequences

- The sidebar section renders **only when something is pinned**. An empty
  "PROJECTS" heading over nothing reads as a section that failed to load, and
  in this product an absence must not look like a fault.
- Pins have an order column and nothing reorders them yet; a new pin is
  appended. Drag-ordering a list capped at about seven is a slice on its own and
  is not owed until somebody asks.
- The sidebar lives in the layout, so the star refreshes the router rather than
  revalidating a path — a path revalidation from a page does not reach the
  layout that renders around it.
- **Teams are still not pinnable**, which is the other half of the same docs/08
  sentence: the teams section lists what you belong to, capped at six. Named
  here so the asymmetry is a decision rather than an oversight.

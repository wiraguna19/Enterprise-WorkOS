# ADR 0026 — A breadcrumb is a promise about what contains this page

- **Status:** accepted
- **Date:** 2026-09-17
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/08` §1, ADR 0024

## Context

Detail screens carried a hand-written back link each: `← People`, `← Teams`,
`← {project} board`, `← Flow`. Seven of them, each phrased slightly
differently, each saying only where the last person came from rather than where
the page sits.

## Decision

**A breadcrumb only where the hierarchy is REAL** — where the levels above
genuinely contain this page:

- a person, under People; their committed-work week, under the person
- a work item, under its project
- a board column, under its board; a project's item list, under its overview
- a team, under Teams
- flow completions, under Flow

**A work item without a project gets no breadcrumb at all.** Requests and
incidents exist on their own (`docs/02` §3), and a trail naming a parent they do
not have is a promise about a page that does not exist.

**The settings screens get none, and that is the decision, not an omission.**
"Settings › Roles" describes a URL, not a containment: somebody arrives there
from the nav or the command palette, the roles screen is not inside anything,
and nothing above it holds a list it belongs to. A trail that is sometimes a
route and sometimes an address is a trail people stop reading — and once they
stop, the ones that ARE hierarchies stop working too.

**The last entry is the page, not a link**, and carries `aria-current="page"`:
offering somebody a link to where they already stand is the kind of politeness
that wastes a keystroke. Separators are `aria-hidden`, because a trail read
aloud as "Projects slash Platform Rebuild slash" is worse than the silence.

## Consequences

- The back links are gone, and with them the seven phrasings. The navigation
  they offered survives: every level above is still one click away, and now the
  page says which levels those are.
- A breadcrumb is server-rendered from data the page already has. No page makes
  an extra request to name its parents — if a page cannot name a parent from
  what it already loaded, that is the signal the hierarchy is not real enough
  for a trail.
- The board column page names its column, the item list names its project's
  key: the trail uses what the person clicked to get there, not a generic
  "Detail".

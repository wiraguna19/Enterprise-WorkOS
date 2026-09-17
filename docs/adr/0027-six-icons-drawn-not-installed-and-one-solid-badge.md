# ADR 0027 — Six icons, drawn rather than installed, and one solid badge per screen

- **Status:** accepted
- **Date:** 2026-09-17
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/09` §5, ADR 0024

## Context

The product had no icon set. Priority is a text chevron, workflow state is a
coloured dot, and everything else is a word — `next`, `react`, `laravel-echo`,
`pusher-js` and a font are the entire dependency list.

That was a reasonable place to be: an icon somebody has to learn is worse than a
word they can read. But the states that repeat on every screen — running,
switched off, failing, expired, erased, system — had nothing to be recognised BY
at a glance, and a page of identical grey pills is a page nobody scans.

The reference offered was a Bootstrap-style badge set: every tone filled solid.

## Decision

**Six icons, drawn in one file.** `check`, `alert`, `clock`, `minus`, `cross`,
`shield`. The product needs six; a library brings a thousand, a build step to
shake them out again, and a second visual language whose stroke weight and
corner radius belong to somebody else. They are 12px on a 16 grid, 1.5 stroke,
`currentColor` — so a badge's tone colours its icon and the icon knows nothing
about tone.

**Every icon sits beside a word, never instead of one**, and every one is
`aria-hidden`. docs/09 §5 has required icon PLUS text since Phase 1, for
priority; this extends the same rule rather than inventing a second one. An icon
alone is a rebus.

**Solid is reserved, not a tone.** The tempting reading of the reference is
"fill them all", and a row of filled badges is a row of traffic lights in which
nothing is louder than anything else — the same failure the inbox demonstrated
with red buttons in ADR 0024. Solid is for the one state on a screen that must
be seen from across the room: work that is late, a rule that is failing, a
person who has been erased. **If a screen shows two solid badges, one of them is
wrong.**

## Consequences

- The dependency list is unchanged, which is the point. `Icon` is 40 lines and
  its whole API is a name.
- A seventh icon is a decision, not a convenience: adding one means it earns a
  place in a set small enough that every member is recognisable. The moment this
  file has twenty, it should have been a library instead.
- `StatusChip` keeps its dot. Workflow state is specified in docs/09 §5 with a
  dot and a label, it is the most repeated component in the product, and a
  redesign of it is not what this ADR is for.
- The icons are drawn as single paths, so an icon needing two paths — a person,
  a file — will not fit the component as written. That is a boundary worth
  hitting deliberately: those are pictures of nouns, and this set is for states.

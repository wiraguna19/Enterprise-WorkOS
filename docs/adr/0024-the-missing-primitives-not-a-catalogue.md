# ADR 0024 — What the interface was missing was primitives, not decoration

- **Status:** accepted
- **Date:** 2026-09-16
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/09-design-system.md`, `docs/08` §2

## Context

The product was described, fairly, as too plain: sections with no edges,
information spread thin, screens that are hard to scan. The references offered
were Kaggle and Hugging Face.

Reading the code rather than the complaint gives a sharper diagnosis.
`docs/09` is a complete and disciplined design system — a warm neutral ramp with
verified contrast, a 14px body scale, four elevation levels, and the rule that a
surface which does not float gets a border rather than a shadow. Almost none of
it was reachable. `components/ui` held `Button`, `Field`, `Avatar`,
`StatusChip`, `PageHeader`, `EmptyState`, `WorkloadBar` — and nothing that makes
a page out of them. There was no container, no table, no badge, no way to lay a
page out.

So every screen improvised. A section was a bare `<h2>` over a `divide-y` list;
a "table" was a stack of `<li>`s with three facts piled vertically inside each
one; a page's width was whatever that page decided. The person profile centred
itself at `max-w-4xl` while the role and denial sections below it ran the full
window — one page with two left edges. `--row-height` and `--cell-padding-*`,
with a `[data-density="comfortable"]` override, had been in `globals.css` since
Phase 1 and were read by no component at all.

**The screens did not look bare because the design was minimal. They looked bare
because eight facts about a person were printed one per line down the left third
of a 1900px display.** Sparse and cluttered at the same time is what missing
structure looks like.

## Decision

**Build the primitives, do not import a catalogue's visual language.** Kaggle
and Hugging Face are browse-and-discover products: thousands of artefacts by
strangers, where cards, download counts, avatars and badges help somebody judge
a thing they have never seen. This is a console for eight to twenty people who
already know each other, answering "what is late" and "who is overloaded". A
card grid over data this familiar adds frames, not information. What is worth
taking from those two is narrower and specific: Hugging Face's object page with
a dense inline metadata line under the title, and Kaggle's real tables.

Five primitives, each earned by this screen rather than speculated:

- **`Panel`** — a bordered container with a heading, an optional description,
  an actions slot and a footer. Elevation 0, radius 6. The heading is a real
  `<h2>` with an id and the section is labelled by it, because the settings
  index and the E2E suite both find things by accessible name.
- **`DataTable`** (`THead`/`TBody`/`Tr`/`Th`/`Td`) — a real `<table>`, with the
  density tokens finally wired to cells. Columns let the eye compare DOWN, which
  is the whole reason tables exist; a `<li>` with three stacked facts does not.
- **`Badge`** — the generic state label, distinct from `StatusChip`, which
  docs/09 §5 reserves for workflow state. "revoked", "erased", "this device"
  were being invented separately on four screens in three different greys.
- **`KeyValue`** — a `<dl>` in a grid. Eight facts in two rows instead of eight.
- **`PageBody`** — one place that owns page width and the main/aside split, so a
  page cannot have two left edges again. The aside holds what somebody GLANCES
  at; the main column holds what they came to change.

**Density is information per row, not boxes per screen.** Where a screen looks
empty the answer is a table with more columns, not a card with a border around
the same three words.

**One screen is converted as proof**: `/people/[id]`, the page where both faults
were plainest. The rest of the product follows only after the direction has been
looked at.

## Consequences

- `PersonProfile` is now four exported pieces rather than one component, because
  the aside and the main column are different questions and were previously
  interleaved in one scroll.
- **Accessible names are preserved exactly.** `Give them`, `On a`, `Which one`,
  `Grant` are what the organization-structure E2E spec drives the grant form by,
  and a redesign that renamed a label would have broken a flow test rather than
  a snapshot.
- Forms move into panel footers, on their own surface. A form sharing a
  background with the list above it read as one more row of that list.
- `tone="danger"` on a panel is how the erase section stops looking like an
  ordinary part of the page. It is the only destructive control in the product.
- The remaining screens are inconsistent until they are converted, and that is
  visible rather than hidden — the alternative was converting forty pages before
  anybody could say whether the direction was right.
- Nothing here changes the token file. The palette, the type scale and the
  elevation ladder in docs/09 were never the problem.

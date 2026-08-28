# 09 — Visual Language & Design System

> The brief asks for something that does not look AI-generated. That is a
> concrete, achievable goal: it means restraint, a real typographic scale, and
> colour that carries meaning instead of decoration.

---

## 1. Design position

**Dense, quiet, and typographic.** The interface should recede and let the
content — work, names, dates, states — carry the visual weight.

Explicitly rejected, per the brief and for good reasons:

| Rejected | Why |
|---|---|
| Gradients as surfaces | They add visual noise with zero information |
| Glassmorphism | Reduces contrast, breaks accessibility, dates quickly |
| Everything-is-a-card | Cards imply grouping; when everything is grouped, nothing is |
| Heavy shadows | Elevation should be rare and mean "floating above" |
| Large border radii | 16 px+ radii read as consumer app, not tooling |
| Decorative icons | An icon that repeats the adjacent label is noise |
| Animated everything | Motion should communicate state change, nothing else |
| Purple-blue SaaS gradient hero | The most recognisable AI-generated tell there is |

The house style instead: **hairline borders over shadows, whitespace over
dividers, weight and size over colour for hierarchy, and colour reserved almost
entirely for state.**

---

## 2. Colour

Neutral-dominant. A screen should be roughly 90% neutral surface and text, 8%
one accent, 2% status colour.

```text
NEUTRALS (slate-based, warm-shifted to avoid a cold "AI blue" cast)
  --n-0    #ffffff    page background (light)
  --n-25   #fbfbfa    subtle surface
  --n-50   #f6f6f5    hover / striped rows
  --n-100  #ececea    borders (subtle)
  --n-200  #dcdcd9    borders (default)
  --n-300  #c2c2be    borders (strong), disabled only — never text (1.79:1)
  --n-500  #71716a    secondary text        ← 4.92:1 on --n-0 (verified)
  --n-700  #45453f    body text
  --n-900  #1c1c19    headings, primary text

ACCENT — a single, restrained ink blue. Interactive elements only.
  --a-50   #eef2ff    selected row background
  --a-500  #3b5bdb    primary buttons, links, focus ring
  --a-700  #2f489f    hover / active

STATE — used only for status, never as decoration
  --s-neutral  #71716a   backlog, cancelled
  --s-info     #2f6feb   todo, assigned
  --s-active   #b45309   in progress          (amber, not yellow — contrast)
  --s-review   #7c3aed   in review, pending approval
  --s-success  #15803d   approved, completed
  --s-danger   #b91c1c   overdue, blocked, rejected
```

Rules:

1. **Colour never carries meaning alone.** Every status has a label; every
   priority has an icon and text; every workload bar has a number.
2. **Status colour appears as a small dot or a subtle tinted chip**, never as a
   full-width coloured row. A board where every card is a different colour is
   unreadable in a week.
3. **All token pairs are contrast-verified by a script** in CI against WCAG AA
   (4.5:1 body, 3:1 large text and UI boundaries). No pair ships unverified —
   `apps/web/scripts/verify-contrast.mjs` fails the build on any regression.

   This rule earned its place during Phase 2. The first palette set `--n-500`
   to `#78786f`, which measures **4.45:1** — visually indistinguishable from
   passing, and caught only because axe-core ran against the rendered pages.
   `--n-300` was simultaneously being used for micro labels at **1.79:1**. Both
   are fixed above; the lesson is that contrast cannot be judged by eye, and
   that a token named for borders will be used as text unless the palette says
   otherwise.
4. **Dark mode is a token remap**, authored at the same time as light mode, not
   retrofitted. Dark surfaces are `#161615`/`#1f1f1e`, not pure black, and
   borders lighten rather than shadows deepening.

---

## 3. Typography

```text
UI          Inter var (system fallback: -apple-system, Segoe UI, sans-serif)
Numeric     Inter with tabular figures — every table number, duration, date
Code        JetBrains Mono
```

```text
display   24 / 32   600    page titles only
h1        20 / 28   600    section titles
h2        16 / 24   600    subsections, card headers
body      14 / 20   400    the default — this is a dense tool, not an article
body-sm   13 / 18   400    table cells, secondary content
caption   12 / 16   500    labels, metadata, timestamps
micro     11 / 14   600    uppercase, +0.04em tracking, section eyebrows
```

Decisions worth stating:

- **14 px body, not 16 px.** Enterprise tools are used at 100% zoom on large
  screens with a great deal of information; 16 px body forces scrolling that
  costs more than it gains in comfort. 13 px for table cells.
- **Three weights only** (400, 500, 600). No 700, no 300. Weight range is the
  cheapest way an interface starts to look sloppy.
- **Tabular figures everywhere numbers align.** Dates and durations that jitter
  between rows look amateurish and slow scanning.
- Line length capped at ~72 characters for descriptions and comments.

---

## 4. Spacing, borders, elevation

```text
SPACE  4-point scale: 2 4 6 8 12 16 20 24 32 40 48 64
RADIUS 2 (inputs, chips) · 4 (buttons, cards) · 6 (panels) · 999 (avatars)
BORDER 1px --n-200 default · 1px --n-100 subtle
```

**Elevation ladder — only four levels exist:**

```text
0  flat, border only          tables, list rows, inline panels
1  0 1px 2px rgba(0,0,0,.05)  cards that are genuinely separate objects
2  0 4px 12px rgba(0,0,0,.08) dropdowns, popovers, command palette
3  0 16px 32px rgba(0,0,0,.12) modals, side panel over content
```

If a surface does not float, it gets a border, not a shadow. This single rule
removes most of what makes generated interfaces look generated.

**Density** is a token set, switchable per user:

```text
comfortable   row 40px   cell padding 12/16
compact       row 32px   cell padding 6/12      ← the default for lists
```

---

## 5. Core component specifications

### Status chip

```text
● In Progress        dot in --s-active, label in --n-700, no background
                     on a tinted background only when the chip is interactive
```

### Priority

```text
⌃⌃ Urgent   (double chevron, --s-danger)
⌃  High     (chevron, --s-active)
–  Medium   (dash, --n-500)
⌄  Low      (chevron down, --n-300)
```

Icon plus text. Never colour alone, never a coloured bar down the side of a row.

### Work item row (the most-used component in the product)

```text
┌────────────────────────────────────────────────────────────────────────┐
│ ☐  ⌃ ENG-142  Implement assignment history       ● In Progress   ⟨SC⟩ │
│               Platform · 2 subtasks · 3 comments        Sep 4  17:00   │
└────────────────────────────────────────────────────────────────────────┘
   ↑checkbox   ↑priority ↑ref  ↑title (primary weight)  ↑status ↑avatar
   second line: --n-500, caption size, secondary metadata only
   overdue: date turns --s-danger and gains a bold weight, not a red row
```

Two lines, one strong element (the title), everything else recessive. Hover
reveals quick actions on the right; they do not occupy space at rest.

### Buttons

```text
primary     solid --a-500, white text          one per screen
secondary   --n-0 with --n-200 border          the common case
ghost       transparent, hover --n-50          toolbars, table actions
danger      solid --s-danger                   destructive confirmation only
sizes       sm 28px · md 32px · lg 36px        (a 48px button belongs on a
                                                marketing page, not here)
```

### Avatars

Circle, initials on a deterministic muted background derived from the user ID,
image when available. Sizes 20 / 24 / 32. Stacks overlap at −6 px with a `+3`
counter beyond four.

### Workload bar

```text
David Park    ████████████████████  44/40 h  ⚠         13 items
              ↑ --s-danger past 100%          ↑ always show the number
Ahmad Rizal   ██████████░░░░░░░░░░  22/40 h            5 items
              ↑ --a-500 under 85%, --s-active 85–100%
```

Always numeric, always with the item count, and unestimated items flagged
explicitly — a bar without its underlying number invites false confidence
(`02` §11).

---

## 6. Motion

```text
instant   0ms      state toggles, selection
fast      120ms    hover, focus, tooltip
base      180ms    dropdowns, popovers, panel slide
slow      240ms    modal enter, page transition
easing    cubic-bezier(.2,0,0,1)   — decelerate; nothing bounces
```

Motion exists to explain a change of state or origin. A side panel slides from
the right because that is where it comes from. Nothing pulses, nothing floats,
nothing animates on scroll. `prefers-reduced-motion` collapses everything to
instant.

---

## 7. Charts

Applies to every chart in reports and dashboards:

- **Never more than one chart per question.** A screen with six charts answers
  none of them.
- Categorical series use the neutral-plus-accent palette, not a rainbow. Above
  five categories, group into "Other" and offer a table.
- Axes start at zero for bar charts. Always.
- Direct labelling in preference to legends where the series count allows.
- Gridlines in `--n-100`, no chart borders, no drop shadows, no 3D, no pie
  charts above three slices.
- Every chart states its time range and freshness ("last 30 days · as of 14:20").

---

## 8. Quality gate before a screen is called done

The checklist a screen must pass in review:

```text
□ Can a new user tell what this screen is for in 3 seconds?
□ Is there exactly one obvious primary action?
□ Does the visual hierarchy match the actual importance of the content?
□ Is anything a card that has no reason to be a card?
□ Is any colour doing decorative rather than semantic work?
□ Do all four states exist — loading, empty, error, partial?
□ Does the empty state offer the action that fills it?
□ Full keyboard operation, visible focus, logical tab order?
□ AA contrast on every text/background pair?
□ Usable at 320 px, and is the mobile interaction model right (not just smaller)?
□ Do numbers align (tabular figures) and dates render in the user's timezone?
□ Does it still look right with 200 rows, a 90-character title, and an empty
  avatar? (Test with realistic seed data, never with three tidy rows.)
□ Would this look out of place in a serious internal tool?
```

If a screen fails the last question, it gets redesigned rather than tweaked.

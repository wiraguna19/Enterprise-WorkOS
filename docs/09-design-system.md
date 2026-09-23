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
RADIUS 6 (buttons, inputs, badges) · 10 (tables, empty states) · 12 (panels)
       · 999 (avatars, chips)
       Radius scales with the ELEMENT. 12 on a 900px panel looks deliberate;
       12 on a 28px button is a capsule and on a 32px input it squeezes the
       text. 16+ anywhere still reads as a consumer app.
BORDER 1px --n-300 on CONTAINERS — panels, tables, inputs, the outer edge of
       anything. --n-200 for the line under a panel header or a table head.
       --n-100 for row dividers inside one.
       Three weights, and the order matters: when every line was --n-200 the
       container had the same edge as the rows inside it, which is a grid of
       boxes rather than a hierarchy. --n-300 is 1.79:1 — below text contrast
       and deliberately so, but visible on a bright screen, which --n-200
       (1.3:1) is not.
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

### Panel  ·  the container every section lives in

Every screen's body sits inside `PageBody`, and every section inside a `Panel`.
A page that improvises — a bare `<h2>` over a `divide-y` list, a page-local
`max-w-4xl`, a caption paragraph floating between two lists — has no edges, and
three screens that each improvise differently have three left edges. Converting
one is mechanical: the header stays, the body goes into `PageBody`, each thought
becomes a `Panel`, long explanations become its `footer`, and a list that meets
the border carries its own `px-4` because the container gave that gutter up.


```text
┌──────────────────────────────────────────────────────────────┐
│ Roles                                    [Manager · everywhere]│  header: n-25, border-b
│ Authority comes from a grant, never from leading a team.      │  description: body-sm n-500
├──────────────────────────────────────────────────────────────┤
│ …table or prose…                                              │  body
├──────────────────────────────────────────────────────────────┤
│ [Give them ▾] [On a ▾] [Which one ▾]            [ Grant ]     │  footer: n-25, forms
└──────────────────────────────────────────────────────────────┘
```

Elevation 0 — border, never shadow. Radius 6. The heading is an `<h2>` with an
id and the section is `aria-labelledby` it. `tone="danger"` for a section whose
actions cannot be undone; `bleed` when the body is a table that should meet the
border (ADR 0024).

#### Forms

A form is a section like any other: the fields go in a `Panel`, and the submit
row goes in its `footer`, where it sits on its own surface instead of under a
rule somebody drew by hand. The error belongs beside the control it is about —
next to the button for a form with many fields, next to the field for a form
with one — and never in both places.

Fields come from `components/ui/Field`. A local copy is how one screen ends up
with lighter borders than the rest of the product, which is exactly what the
New work item form had.

### Data table

```text
ROLE          ON                         ACTION      ← micro, uppercase, n-500, bg n-25
Manager       on team Frontend           Revoke      ← row height --row-height, hover n-25
```

A real `<table>`: a screen reader announces the column header with each cell.
Cells read `--cell-padding-x/y`, so the comfortable/compact switch in §4 is real
rather than decorative. One strong column, the rest `muted`.

### Badge  ·  states that are not workflow states

```text
✓ running   − switched off   ⚠ 3 recent failures (SOLID)   🛡 system
[Manager · everywhere]  info      [erased] danger, solid
```

`StatusChip` is for where a work item is in its workflow. Everything else —
account state, scope, "this device" — is a `Badge`, and `neutral` is the
default: colour still carries meaning, not decoration.

Icons come from the six in `Icon.tsx` (check, alert, clock, minus, cross,
shield), always BESIDE the word and never instead of it, always `aria-hidden`.

`solid` is reserved for the one state on a screen that must be seen from across
the room — late work, a failing rule, an erased person. Two solid badges on one
screen means one of them is wrong (ADR 0027).

#### Navigation counters

```text
My Work   [ 4 ]  danger, outlined      Inbox   [ 12 ]  accent, outlined
```

The two counters in the sidebar are pills, not grey text, and they are not the
same colour: My Work counts work that is overdue or due today (a deadline),
Inbox counts unread notifications (a pile). They are OUTLINED, never solid —
the chrome is on every screen at once, so a filled counter would outshout the
one solid badge each screen is allowed, including the late-work badge on My
Work itself. The mobile bar is the exception: at 10px over an icon an outline
is a smudge, so it fills. Numbers are `tabular-nums` and the count is announced
with its unit ("My Work, 4 due or overdue"). There are still only two counters
in the whole navigation; if everything has a badge, nothing does.

### Breadcrumb

```text
Projects › Platform Rebuild › ENG-142
```

Only where the levels above genuinely CONTAIN the page: a work item under its
project, a person under People, a board column under its board. Not for the
settings screens — "Settings › Roles" is an address, not a containment, and a
trail that is sometimes one and sometimes the other stops being read (ADR 0026).
Last entry is the page itself, `aria-current="page"`, not a link.

### Toast  ·  saying that something happened

```text
┌──────────────────────────────────────────────┐
│ Granted. It applies on their next request. ✕ │   bottom-right, 5s, dismissible
└──────────────────────────────────────────────┘
```

Only for an effect that happens OUT OF SIGHT, or an act consequential enough to
say out loud: grant, revoke, deny, erase, end a session, revoke an invitation,
switch a rule off. If the effect is visible where the person is standing, the
screen is the confirmation and a toast is noise (ADR 0025).

Errors are never toasts. They stay inline beside the control that failed.
`aria-live="polite"`, because this announces something that already went right.

### Key/value grid

Facts about one thing, in two to four columns. Eight facts belong in two rows,
not eight; a `<dl>` so the label/value pair survives being read aloud.

### Page body

One owner of page width and of the main/aside split. The aside holds what
somebody glances at (reporting line, this week); the main column holds what they
came to read or change. A page must not set its own max-width.

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
primary      solid --a-500, white text        one per screen
secondary    --n-0 with --n-300 border        the common case
ghost        --n-25 with --n-200 border       quiet: table actions, cancel
affirmative  --a-50, --a-700 text, a-tinted   it GIVES or STARTS something
destructive  --s-danger tinted outline        it TAKES something away
danger       solid --s-danger                 final confirmation only
sizes        sm 28px · md 32px · lg 36px      (a 48px button belongs on a
                                               marketing page, not here)
```

**Colour follows consequence, not the verb.** Three groups and two hues:
accent for grant / switch on / save / create, red for end / revoke / deny /
switch off, neutral for everything else — which is most things. The tempting
scheme is a colour per kind of action, and it fails the moment a table row
holds three of them: once every button is coloured, no button stands out.

**Every variant rests with an affordance.** `ghost` used to be bare text until
hovered, so "Revoke", "End" and "Switch off" read as words in a table — and on
a touch screen the hover state never arrives at all. All variants also carry a
`focus-visible` ring: the reset removes the browser outline, and for a while
nothing replaced it.

**A state is not an action.** "running", "switched off", "expired", "erased"
are `Badge`s, not coloured prose: they report rather than offer, and that is
where colour is most at home.

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

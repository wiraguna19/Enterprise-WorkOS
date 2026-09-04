# ADR 0012 — A board drag is a transition, and the keyboard can perform it

- **Status:** accepted
- **Date:** 2026-09-04
- **Phase:** 5 (Collaboration) / 7 (Enterprise Foundation)
- **Relates to:** `docs/07-frontend-architecture.md` §1 (dnd-kit, TanStack
  Query), §3 (the worked example), §5 (accessibility),
  `docs/11-testing-strategy.md` §4 flow 10, `docs/03-database-schema.md` §3
  (fractional `position`), ADR 0006

## Context

The board shipped in Phase 3 as a read-only view. Its cards are `<Link>`s;
nothing is draggable and nothing ever was — while the component's own comment
says "each item really is a discrete draggable object". `POST
/work-items/{ref}/move` has accepted `before_id`, `after_id` and `to_state_id`
since the same phase, and `BoardOrderingTest` has proven all three. So this is
the same shape as the transition button before `6d2d146` and the progress bar
before `707cfea`: **the API is proven, the affordance is decoration.**

`docs/07` describes how the drag was meant to work, and describes an app that
does not exist here. It names **dnd-kit** for the interaction and a **TanStack
Query cache** holding an optimistic update that is reconciled or reverted.
Neither library is a dependency. This app does its mutations with Server
Actions against an HttpOnly session cookie, and its freshness with
`revalidatePath` — a decision taken in Phase 3 and never written down. Building
the drag forces the question, so it is written down now.

## Decision

### 1. The server is the only source of truth about where a card is

No optimistic cache. The card moves when the server says it moved.

`docs/07` chose optimism for a good reason — "this is the interaction the whole
board is judged on" — and the price is a class of bug this product cannot
afford. An optimistic board shows the card in its new column, then puts it back
when a guard refuses, and the two states differ by a rule the user cannot see.
Worse, the revert is the one path nobody exercises: it needs a workflow that
refuses, which the happy path never produces.

The interaction stays honest instead: the card is visibly **in flight** while
the action runs, and lands in exactly one place — where the server put it.
Locally this is a sub-100ms round trip. If a real deployment makes that feel
slow, the fix is a measurement and a follow-up ADR, not a guess taken now.

### 2. A drop into another column is a transition, not a position write

Dropping a card into a different column posts to
`/work-items/{ref}/transition`; dropping it in its own column posts to
`/move` with its neighbours. One endpoint could do both — `move` delegates to
`transition` internally — but the two are different acts:

- A transition runs guards, may require a comment, and writes activity.
- A reorder is a preference about a list, and needs no permission beyond edit.

Sending both through `move` would mean the board asking a weaker question than
the status menu asks for the same outcome, which is exactly the gap this slice
closed on the API side: `move` now demands `work_item.transition` when the
body changes the state. Two endpoints reaching one behaviour must ask the same
question of the caller, or the weaker one becomes the way in.

Consequence, accepted deliberately: **a cross-column drop does not carry a
position.** The card lands where its new column's ordering puts it, and the
user can drag it again to place it. Combining the two would mean either a
transaction spanning two requests or a `move` call that re-opens the gap.

### 3. A transition that needs a comment cannot be completed by dropping

The graph marks some edges as requiring a reason (`requires_comment`). A drag
cannot supply one, so a drop onto such a column **asks**, in place, with the
same prompt the status menu uses — and the card does not move until the reason
is given. "Request changes" without saying what to change is a silent
rejection, and a gesture that produced one would be a way around a rule the
rest of the product enforces.

### 4. The keyboard performs the same move, through the same code

`docs/07` §5 requires it, and dnd-kit was chosen for it. Without dnd-kit it is
implemented directly, and this is the part worth the words:

- A card is a `<button>` in a list, focusable, labelled with its reference and
  its current column.
- **Space or Enter picks it up.** The board announces it, via a live region.
- **Left and Right choose the destination column**, announced as they change.
- **Space or Enter drops it. Escape cancels.**

Pointer drag and keyboard drag converge on one function — "put item X in column
Y" — so there is no second path that can rot. The pointer half uses native HTML5
drag events rather than a pointer-event reimplementation, because the native
ones are what assistive technology and the browser already understand, and the
keyboard half is not a fallback bolted onto them but the same call.

Touch is deliberately not given a drag. Native HTML5 drag does not exist on
touch, and a long-press gesture inside a horizontally scrolling board fights the
scroll. On a phone the status control on the card's own page is the way to move
work — which is the flow already proven end to end at 375px (flow 15).

### 5. `position` stays fractional and one row is written

Unchanged from `docs/03` §3, and already proven. The drag sends the ids of the
new neighbours; the server picks a value between them. Nothing is renumbered.

### 6. A column is a window onto its state, and says how much it left out

Found while building this: `GET /projects/{key}/board` hydrated **every** work
item in the project into Eloquent models. On the demo seed that is a few hundred
and looks perfectly healthy. Against the volume fixture it exhausted PHP's
128 MB limit and the board answered 500 — with no visible cause in the code,
because what was wrong was the absence of a bound.

A column now returns its **true `total`**, the first 50 cards by position, and
the `hidden_count` between them. The count is a fact about the column; the cards
are a list for the reader; the two may differ as long as the difference is
stated. That is the same rule every Insights list follows, and the reason it is
stated as its own number rather than left to be inferred from a length: a reader
who has to subtract two numbers to discover a list is truncated will not
subtract them.

Fifty is a window, not a page — there is no cursor. Someone who needs the
hundredth card in Backlog wants a list, not a board.

The same honesty applies upward: the board header's item total comes from the
columns' counts, and its overdue figure is prefixed "at least" whenever any
column is truncated, because that number can only be counted among the cards
that came back.

## Consequences

- `docs/07` §1's dependency table is now wrong in two rows for this app. It is
  left as written and this ADR is the record — the table describes an intent,
  and the deviation is the decision.
- The revert path in `docs/07` §3 (3b/3c) does not exist, because there is
  nothing optimistic to revert. A refusal is shown where the card still is.
- The board is the only place in the product with a bespoke interaction, so it
  is also the only place where an E2E test is the only test that can prove the
  behaviour. Flow 10 is therefore not optional.
- **A truncated column currently has no way to reach the rest.** No screen
  lists one column's items, and the footer says so plainly rather than linking
  to a page that does not answer the question. That is the follow-up this ADR
  owes: either a per-column cursor on the board endpoint, or a project item
  list filtered by state.
- If a future phase adds dnd-kit — for nested boards or multi-select drag — the
  convergence point in §4 is the seam to build on, and this ADR is what should
  be revisited first.

## Alternatives rejected

**Add dnd-kit and TanStack Query as `docs/07` specifies.** Two libraries and a
client cache layer, introduced for one screen, in an app whose every other
mutation is a Server Action. The cache would then hold state for one route and
nothing else, and the next reader would reasonably assume the rest of the app
used it too.

**Make the whole card a drag handle with pointer events.** More code than the
native API, no benefit on desktop, and it would have to reimplement the drop
semantics the browser already provides.

**Keep the board read-only and call the status menu sufficient.** The move is
reachable from the card's page, so nothing is impossible today — but a board
whose cards look draggable and are not is worse than a board that never
suggested it, and `docs/11` flow 10 exists precisely to stop that from being
the answer.

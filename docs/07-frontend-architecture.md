# 07 — Frontend Architecture

---

## 1. Stack decisions

| Concern | Choice | Reason |
|---|---|---|
| Framework | Next.js 16, App Router, React 19 | RSC lets dense enterprise screens render server-side with little client JS; the server layer is also the auth boundary (`06` §1) |
| Language | TypeScript, `strict`, no `any` in committed code | The domain has many similar-looking IDs; types are the cheapest defence |
| Styling | Tailwind CSS + CSS variables for the token layer | Utility classes keep style local to the component; tokens keep it consistent and themeable |
| Primitives | Radix UI, wrapped in `packages/ui` | Accessibility (focus trap, ARIA, keyboard) is genuinely hard; Radix is unstyled so the visual language stays ours |
| Server state | TanStack Query (client islands only) | Cache invalidation, optimistic updates, retry — solved |
| Client state | Zustand for genuine UI state (command palette, sidebar, selection) | Small; no reducer ceremony for three booleans |
| Forms | React Hook Form + Zod | Zod schemas are generated from the OpenAPI contract, so client and server validation cannot drift |
| Tables/boards | TanStack Table + TanStack Virtual | Virtualization is required above ~100 rows |
| Drag & drop | dnd-kit | Accessible (keyboard-operable drag), unlike most board libraries |
| Charts | Recharts / visx, deliberately sparse | See `09` on chart restraint |
| Dates | `date-fns` + `date-fns-tz` | Tree-shakeable; timezone handling is explicit rather than ambient |
| Testing | Vitest + Testing Library + Playwright | |

**No Redux, no tRPC, no GraphQL client.** Server state belongs to Query, and the
contract is REST + generated types.

---

## 2. Rendering strategy

The default is **React Server Components**. A component becomes a Client
Component only when it needs interactivity, browser APIs, or subscription state
— and then only that leaf, not its parents.

```text
app/(app)/projects/[id]/board/page.tsx        RSC — fetch board data server-side
 └── <BoardShell>                              RSC — layout, headers, filters render
      ├── <BoardFilters>                       Client — interactive
      └── <BoardColumns items={…}>             Client — dnd + virtualization
           └── <WorkItemCard>                  Client (needed for drag handle)
```

Rules:

1. **Fetch on the server; mutate from the client.** Initial data arrives with the
   HTML. Mutations go through Server Actions or route handlers, which then
   revalidate the affected cache tags.
2. **`'use client'` is a decision, not a habit.** A PR that adds it to a layout
   or page component needs a reason in the description.
3. **Streaming with Suspense** for expensive secondary panels — the work item
   detail renders immediately, the activity timeline and related items stream in.
4. **No client-side data fetching on first paint.** A spinner where the server
   could have rendered content is a defect.

### Caching

| Data | Strategy |
|---|---|
| Session / current user | Request-memoized on the server, `cache()` |
| Org reference data (states, roles, tags, people list) | Cached with tag `org:{id}:reference`, invalidated on change |
| Work item lists | Not cached across requests (freshness matters); TanStack Query caches within a session with a 30 s stale time |
| Work item detail | Tagged `work-item:{id}`; mutations revalidate the tag |
| Reports | Cached 5 min, explicitly labelled "as of HH:MM" in the UI |

---

## 3. Data flow for a mutation

Worked example — dragging a card to another column:

```text
1. User drags card → optimistic update in the Query cache
                     (card moves instantly; this is the interaction the whole
                      board is judged on)
2. POST /work-items/{id}/transition  { to_state_id, position, lock_version }
3a. 200 → reconcile with the server response
3b. 409 → revert the card, toast "Someone changed this task", refetch
3c. 422 (illegal transition) → revert, show which transitions are allowed
4. Invalidate: work-item:{id}, project board list, my-work counts
```

**Optimistic updates only where the server almost always agrees** — reordering,
status change, assignment, comment posting. Not for approvals, deletions, or
permission changes: optimistically showing "Approved" and then reverting is
worse than a 400 ms wait.

---

## 4. The permission-aware UI

```tsx
// permissions come from the resource payload (05 §3) — never recomputed client-side
const { permissions } = workItem;

{permissions.assign  && <AssignButton  … />}
{permissions.transition && <StatusPicker … />}
```

- A `<Can>` helper reads the resource's permission block. There is **no
  client-side permission engine** — duplicating the rules guarantees divergence.
- When an action is unavailable because of *state* rather than *permission*
  (cannot complete while blocked), the control is **disabled with an explanation**
  rather than hidden. Hidden controls make users think the app is broken;
  explained ones teach the workflow.

---

## 5. Performance practices

- **Route-level code splitting** plus dynamic import for heavy, rarely-used
  surfaces (timeline/Gantt, rich text editor, chart bundles, workflow builder).
- **Virtualize** any list that can exceed 100 rows: board columns, work item
  lists, activity timelines, search results, people directory.
- **Skeletons that match the final layout**, so there is no layout shift. A
  spinner in the middle of the page is a last resort.
- `next/image` for avatars and attachments with explicit dimensions.
- **Bundle budget enforced in CI**: a PR that pushes a first-load route past
  200 KB gzipped fails and must justify itself.
- Prefetch on link hover for the primary navigation paths (project → board,
  list row → detail).

---

## 6. Accessibility as a build requirement

Not a Phase 7 audit. Enforced in CI via `eslint-plugin-jsx-a11y` and
`axe-core` assertions in Playwright on the core screens.

- **Every action reachable by keyboard**, including drag-and-drop (dnd-kit
  keyboard sensor: pick up, arrow to move, drop).
- Visible focus rings, never `outline: none` without a replacement.
- Semantic landmarks (`nav`, `main`, `aside`), correct heading order.
- Live regions announce async results ("Task moved to In Review").
- WCAG 2.1 AA contrast on every token pair, verified by a script over the token
  file rather than by eye.
- Dialogs trap focus and restore it on close; Escape always closes.
- `prefers-reduced-motion` respected — all transitions collapse to instant.
- Colour is never the sole carrier of meaning: status has a label, priority has
  an icon plus text, workload bars carry a numeric value.

---

## 7. Error, empty, and loading states

Every data surface defines four states. A screen is not "done" until all four
exist — this is the most commonly skipped work in an application like this, and
its absence is what makes software feel unfinished.

| State | Requirement |
|---|---|
| Loading | Layout-matched skeleton |
| Empty | Explains what belongs here **and offers the action that creates it**. Never a bare "No data." |
| Error | Says what failed, offers retry, includes the `request_id` for support |
| Partial | If one panel of a dashboard fails, the rest still render — dashboards degrade, they do not blank |

Error boundaries at layout, route, and heavy-widget level. A crash in the
activity timeline must not take down the work item page.

---

## 8. Real-time (Phase 5)

Deliberately last. The mechanism is a WebSocket channel per subject
(`org.{id}.work-item.{id}`, `org.{id}.user.{id}`), broadcasting the same domain
events the backend already emits.

Scope is limited on purpose:

- Notification badge and inbox
- Presence and "someone else is editing" indicators on a work item
- Board card moves made by others
- Comment stream

**Not** live-collaborative editing of descriptions. That requires CRDTs and an
entirely different persistence model; it is not what this product is for, and
pretending otherwise costs months.

Everything works without the socket. Real-time is an enhancement layered over a
system that is correct when polled — if the socket drops, the app degrades to
refetch-on-focus and nothing breaks.

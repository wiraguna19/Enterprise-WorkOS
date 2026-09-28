# ADR 0054 — Closed work leaves the working set

- **Status:** accepted
- **Date:** 2026-09-28
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/10` Phase 7, `docs/03` §3, ADR 0012, ADR 0039, ADR 0053

## Context

docs/10 lists "archival strategy for closed work" under Phase 7 Scale, and
nothing else says what it means. What it has to answer is plain from the data:
work items are never deleted in the ordinary course — done is done, and the
comments, files, transitions, approvals and reports all hang off the row — so
`work_items` only grows, and every screen that shows WORK pays for every item
ever finished. The volume fixture found a board falling over at 50 000 rows; a
bound fixed that (the board shows 50 cards a column), but the Done column's
`total`, the browse list and a project's items still carried everything.

## Decision

**Archived is a flag on the row. Closed work that has been untouched for as
long as the organization keeps it in view is archived nightly; the working-set
screens leave it out; everything else keeps it.**

- **A flag, not a second table.** Moving rows out would break every foreign
  key that points at them and make every report read two tables.
  `work_items.archived_at`, with a partial index for a project's live work
  (`idx_wi_project_live`) and one for the sweep's candidates.
- **Only closed work, and reopening undoes it — in the database.** A CHECK says
  an archived item is done or cancelled; a trigger clears the flag whenever
  `state_category` leaves those two. Every path that reopens work — a
  transition, a drag, a rule, the workflow editor recategorising a state — is
  covered without any of them having to remember.
- **Each organization says when:** `organizations.archive_closed_after_days`,
  7 to 3650, **90 by default**, null for never. Changed on the Organization
  settings page by `organization.manage_settings`, recorded in the activity
  log, and with no password prompt: it changes where finished work is shown,
  not who may see or do anything.
- **"Untouched" is `updated_at`.** An item still being edited after it closed
  is not finished with. Restoring one resets the same clock, so it is not
  archived again that night.
- **`work:archive-closed-work`**, nightly at 03:30, across every organization,
  in batches of 5 000 — the first run on a busy organization is everything it
  ever closed. No activity entry per item: the system archiving ten thousand
  rows is not ten thousand things anybody did. Restoring one is, and is.

**What leaves out archived work:** `GET /work-items` by default
(`filter[archived]=include|only` to ask; an unknown value is a 422, ADR 0039),
and the board's columns — whose `total` is the working set, with
`archived_count` beside it so a Done column that shrank overnight says why.

**What keeps it:** the item itself (it opens by reference, with a notice and a
"bring back to the board" button), every report and insight, project progress,
search, and the activity history.

## Consequences

- A board's Done column and the browse list stay the size of recent work.
- The seed's finished items older than ninety days will be archived the first
  time the scheduler runs in development. That is the feature working; a board
  says how many and links to them.
- The browse screen's "Include archived" toggle and a board column's "see them"
  link are the two ways back to the archive from a list.

Still owed:

- **My Work's "completed" view** is already bounded to thirty days and was left
  alone; **the calendar and a project's health drill-throughs** still include
  archived items, which is correct for health (a signal counts all of it) and
  arguable for the calendar.
- **Retention** — actually removing data after years — is a different policy
  with legal weight, and is not this.

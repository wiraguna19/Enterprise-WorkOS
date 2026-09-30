# ADR 0056 — A project can have milestones

- **Status:** accepted
- **Date:** 2026-09-30
- **Phase:** after Phase 7 (`docs/10`, the queue, item 2)
- **Relates to:** `docs/02` §10, `docs/05` §1, `docs/08` §2, `docs/11` §4
  flow 3, ADR 0008, ADR 0040, ADR 0041

## Context

`milestones` shipped in Phase 2. Project health judges a project by them
(ADR 0008's `milestones` signal), the calendar draws them, `work_items` can
point at one — and nothing could make one. `docs/05` §1 lists
`GET|POST /projects/{id}/milestones`; no route existed. `milestone.manage` was
granted to managers and sat on the list of permissions that mean nothing. So
every project outside the seed had no milestones, and its health said
"unknown" on that signal however carefully somebody had planned it.

## Decision

**`/projects/{key}/milestones` — list, create, change, remove — and a
Milestones panel on the project overview.**

- **Nested under the project key, like its members.** A milestone is only ever
  reached through its project, and the project's visibility is the
  milestone's: a milestone of a project you cannot see is a 404, and so is one
  of a different project than the URL names.
- **Guarded on `project.view`; the policy decides the writes.**
  `ProjectPolicy::manageMilestones` is the project's owner or managers, or
  `milestone.manage` — the same shape as members and settings (ADR 0040, 0041),
  so the coarse route layer never overrules a project role.
- **`completed_at` follows `status`**, set on the way into completed and
  cleared on the way out; the table's CHECK couples them. Reopening is
  allowed.
- **Listed in date order, undated last**, each with how much work it groups
  and how much of that is open.
- **Every change goes to the project's activity** (`milestone_added`,
  `milestone_updated`, `milestone_removed`), with the milestone's name in the
  entry so it reads without opening anything.
- **On the overview, not in settings.** A milestone is part of what the project
  is — the health signal directly above the panel is judged by them — and the
  people reading a project need its dates. Changing them happens in place, for
  whoever may. A past-due milestone says "past due" in words, measured against
  the reader's own calendar day.

## Found on the way

**Removing a milestone that had work in it would have failed.** The foreign
key from `work_items` is composite, `(organization_id, milestone_id)`, with
`ON DELETE SET NULL` and no column list — and Postgres then nulls BOTH
columns, which `work_items.organization_id NOT NULL` refuses. The service
detaches the work itself before the delete (soft-deleted items included, and
without touching `updated_at`, which ADR 0054 archives by). The same pattern
exists on `fk_work_items_project`; nothing deletes a project today, so it is
recorded here rather than changed.

## Work in a milestone (added the same day)

The create and edit forms offer the item's project's milestones. The create
form asks for them when the project changes and clears the choice when it
does; the edit form reads them for the item's own project, and says why the
field is empty for work with no project.

**The rule is enforced in the service, and it was not before:** a milestone
of the item's OWN project, or none (`work_item.milestone_not_in_project`).
Creation checked only that the milestone was the organization's, so an ENG
item could be filed under a FIN milestone — counted there, invisible from
there. An edit checked only that the id was a uuid, so one that named nothing
reached the foreign key and came back a 500. Neither could be sent while
milestones had no interface; the picker is what made them worth closing.

## Not done

- **Ordering by hand.** `position` exists and is kept; undated milestones sort
  by it. There is no drag.
- **The timeline view** (`docs/08` §2) that would draw milestones and
  dependencies together.

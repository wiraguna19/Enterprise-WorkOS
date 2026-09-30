# ADR 0055 — One change to many work items

- **Status:** accepted
- **Date:** 2026-09-30
- **Phase:** after Phase 7 (`docs/10`, the queue, item 1)
- **Relates to:** `docs/05` §1 and §5, `docs/08` §8, `docs/11` §4 flow 11,
  `docs/06` (audit events), ADR 0040

## Context

`docs/05` §1 has listed `POST /work-items/bulk` ("bounded batch: assign /
transition / tag") since Phase 1, and §5 says how it answers: at most 100
subjects, per-subject results, never all-or-nothing. `docs/08` §8 promises a
floating action bar on every list with assign / status / due date / tag /
delete. `docs/11` flow 11 tests "select five items, assign and set due date".
None of it existed: no route, no service, no checkbox.

## Decision

**`POST /work-items/bulk` takes a list of references and one change — an
assignee, a due date, or both — and answers each reference on its own. The
browse page (`/work`) selects rows and drives it.**

- **Two actions, not five.** Assign and due date are what flow 11 needs and
  what have no per-item surprises. Status is left out on purpose: a transition
  is a workflow edge, and five items in five states under two workflows have no
  single "move to" that means the same for all of them — the honest control
  for that is a different design, not a select box. Tag has no tag UI to lean
  on yet. Delete is irreversible per item and `docs/08` §8 says irreversible
  acts get a dialog naming the consequence; that is its own piece of work.
- **Per subject, and through the single-item services.** A due date goes
  through `WorkItemService::update()`, an assignee through
  `AssignmentService::assign()` — so the activity entry, the notification, the
  date check and the realtime push are the ones a single edit produces. The
  per-item policy (`assign`, `update`) is asked for every subject.
- **Authorised before written.** Both abilities are checked for a subject
  before either change is made to it, so the common refusal never leaves an
  item half-changed. A failure AFTER that (a domain refusal from the second
  write) can leave the first standing: each service commits and dispatches its
  events itself, and an outer transaction would dispatch events for writes it
  could still roll back. `docs/05` §5 says "transactional per subject"; this is
  per subject, per action, and the difference is named here rather than hidden.
- **The route's gate is `work_item.view`.** What may be done to each item is
  the policy's per-item answer; a route that also demanded `work_item.update`
  would refuse somebody who may only assign, before any item was asked about
  (the defect where two authorization layers answer differently and the
  coarse one silently wins).
- **The request is refused as a whole only when it is wrong for everyone:** no
  references, more than 100, nothing to change, or an assignee who is not an
  active member here (another organization's membership is simply not found).
- **"Already hers" is success, `changed: false`.** Not a refusal: there is
  nothing for anyone to fix. The same for a due date already on that day.
- **Not found and not visible are one answer**, as on `GET /work-items/{ref}`:
  a bulk endpoint must not be a way to probe a hundred references at a time.
- **One audit entry per request** (`work_item.bulk_updated`, listed in
  `docs/06` since Phase 1), naming the references, the change and the counts.
  The per-item history is the activity log's, as for any edit.
- **Selection is one page.** "Select all" means the rows on screen. Every item
  matching a filter is a bigger promise — a server-side selection and a count
  nobody has seen — that this does not make.
- **What failed stays selected**, named in the bar with the API's sentence; what
  succeeded drops out. Fix the cause, press the same button.

## Found on the way

`PATCH /work-items/{ref}` moving only the due date was checked against the
stored start date by nothing above the database: the CHECK constraint refused
it and the answer was a 500. Every bulk "set due date" over a list containing
one late-starting item would have inherited that. `WorkItemService::update()`
now checks the merged dates and refuses by name
(`work_item.due_before_start`, "ENG-12 starts on …, so it cannot be due on …").

## Not done

- **Undo.** `docs/08` §8 asks for an undo toast on bulk assign. The previous
  holder and date are in each item's history, but there is no one-click way
  back yet.
- **The `X` shortcut** for selecting (`docs/08` §6).
- **The other list surfaces** — My Work, a project's list — still have no
  selection. The browse page is the one list that shows any work at all.
- Status, tag and delete, above.

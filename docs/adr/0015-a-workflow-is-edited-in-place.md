# ADR 0015 — A workflow is edited in place, and the destructive edits are refused by name

- **Status:** accepted
- **Date:** 2026-09-11
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/02-domain-model.md` §7, `docs/03-database-schema.md` §3,
  ADR 0006 (the Work ↔ Workflow cycle), ADR 0014 (the rule builder)

## Context

`workflows` carries `version` and `superseded_by_id`, written by nothing since
Phase 2. They describe copy-on-write versioning: editing a live workflow would
produce version N+1 and supersede the old one.

The seed alone puts ~180 work items on the default workflow, each holding a
`workflow_state_id`. Copy-on-write is therefore not a save; it is a migration.
Every state of the old graph has to be mapped onto the new one, every item in
flight moved, and the mapping decided for the case the feature exists for — a
state that was REMOVED. Most of the work is the migration, and a wrong
migration silently moves real work into the wrong column, which is the failure
mode this codebase is least able to detect: nobody reports a wrong number,
because it looks computed.

Meanwhile the edits people actually ask for daily are dull: rename a status,
add one, draw a move, remove a move nobody uses.

## Decision

**Edits happen in place. `version` and `superseded_by_id` stay unwritten**
until something needs the migration that would give them meaning.

Four edits are refused, each with a stable `details.refusal` name and 409 —
well-formed request, sufficient permission, destructive against the graph as it
stands:

| refusal | why |
|---|---|
| `category_is_load_bearing` | Every list, board, report and overdue rule reasons about the CATEGORY. Changing it does not change the future — it rewrites what a finished quarter counted, with no record that the meaning moved. |
| `key_is_matched_by_rules` | Rules match `to_state_key`. A renamed key leaves every rule that named it evaluating to false — forever, without error. |
| `state_holds_work` | The items would point at a row that no longer exists. |
| `state_has_transitions` | The foreign key WOULD cascade them away, which is the argument for refusing: the graph left behind is not the one the person looked at before pressing the button. |
| `would_strand_work` | Removing the last move out of a state holding work leaves those items unable to be advanced, cancelled or unblocked by anybody. The only symptom is somebody reporting that their buttons are gone. |

The LABEL is always free. It is the customer's word and nothing in the product
reads it — which is the whole reason a state's category and key are separate
columns from its label (docs/02 §7).

Two capabilities are deliberately absent rather than refused, because their
absence costs nothing today: **reordering states** — `(workflow_id, position)`
is UNIQUE and does not defer, so a swap needs a renumbering dance no screen has
asked for — and **guards on a new transition**, which would be a second, smaller
authorization language beside docs/06 §2, arrived at by accident from an edit
form.

## Consequences

- **An edit applies to work already in flight**, immediately. That is the
  honest reading of an in-place graph and matches what an administrator
  renaming "In Review" to "QA Gate" expects. It is also why the category cannot
  move: a rename is cosmetic, a recategorisation is retroactive.
- **`version` and `superseded_by_id` are now documented as unused**, rather
  than looking like a feature somebody forgot to finish. The day a customer
  needs a state removed while work sits in it, this ADR is superseded and the
  migration is the work.
- Every refusal is surfaced verbatim by the interface. Hiding the control
  instead would leave a person guessing why the button is missing — and the
  refusals are the most useful sentences this screen has.
- The checks run inside the transaction that performs the write, with the
  workflow row locked. Checking first and writing after passes every test and
  fails under two administrators; the department move learned that already.

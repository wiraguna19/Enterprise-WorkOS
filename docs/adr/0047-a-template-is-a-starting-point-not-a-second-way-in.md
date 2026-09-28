# ADR 0047 — A template is a starting point, not a second way in

- **Status:** accepted
- **Date:** 2026-09-27
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/10` Phase 7, `docs/02` §7, ADR 0014, ADR 0038

## Context

docs/10 lists templates twice in Phase 7 — "templates" under Workflow and
"work item templates" under Extensibility — and docs/02 §7 names a rule action
`create_work_item(template)`. Grepping the repository for the word found one
template: the `template` jsonb column on `recurrences`, which a recurrence
reads when it fires and which no form can pick. There was no way to say "new
incidents look like this" and have the create form start from it.

The obvious build is `POST /work-items/from-template/{id}`: pick a template,
get an item. It is the wrong build here, for a reason this codebase has already
paid for. Creating a work item is one behaviour guarded by one endpoint —
`work_item.create`, the required custom fields of ADR 0038, the validator that
refuses an empty string, project visibility. A second endpoint that creates
work would need its own copy of every one of those, and the history of `/move`
beside `/transition` is what happens to the copy: it was the weaker door, and
it stayed open for three phases because it looked like it was checking.

## Decision

**A template PREFILLS the create form. It creates nothing.**

Choosing one is a link — `/work/new?template=ID`, carrying `?project=KEY` when
the form was opened from a project — and the form opens with the template's
values in its inputs. The person sees every field, changes what they like, and
submits to the same `POST /work-items` as a blank form. To the API there is no
difference between a prefilled field and a typed one, which is the point.

The rest follows from that:

- **One table, `work_item_templates`**, in the Work module: a name (unique per
  organization, case-insensitively, decided by the index), a one-sentence
  purpose, and `fields` jsonb.
- **`fields` holds only what a form can prefill and a template can honestly
  keep**: title, description, type, priority, estimate, `due_in_days`, custom
  field answers. It is the recurrence template's vocabulary on purpose, so that
  the day a recurrence or a rule may name a template the two shapes are one.
- **Refused by name, all at once, each with its reason**: people (a template
  outlives the people it would name), a project, a parent, a milestone, and
  absolute dates (the same date forever). An unknown key is refused too. A key
  silently dropped is a setting that appears to save and does nothing.
- **Custom field answers are checked against today's definitions when the
  template is saved** — `CustomFieldValues::normalize()`, the same refusals
  `write()` makes, without writing. A template holding an answer the create
  form would refuse is a trap with a friendly name. When a field is retired
  AFTER the template was written, the form skips that answer and **says so**
  ("Skipped, because the field no longer accepts it: …"), and the editor says
  that saving drops it.
- **A template that fills in nothing is refused.** It would appear in the
  picker, be chosen, and change nothing.
- **`fields` is replaced on save, not merged**, because a merge can never remove
  a key.
- **The read is behind `work_item.create`**; writes are behind the new
  `work_item_template.manage` (org admin). The person who reads a template is
  the person filling in the form. ADR 0038 learned this the expensive way: a
  create form reading an administration endpoint made one required field a
  lockout for everybody but an admin. Unlike custom fields, one list serves
  both screens — a template holds nothing the form may not see.
- **Deleted, not retired.** Custom fields are retired because records keep
  their answers. Nothing refers to a template, so a retired one would be a row
  kept for nobody. The audit entry keeps what it held.
- **No `template_id` on work items.** A prefill the person may change in every
  field does not make an item "from" the template in any sense a report could
  rely on, and a column recording a relationship that may be false is the
  column this project keeps finding written by one thing and read by nothing.

### The vocabulary, served

The create form kept its own copy of `WorkItemModel::TYPES` and `PRIORITIES`,
under a comment saying the fix would be "one endpoint that names them, not a
fourth copy". The template editor needed the same two lists, so
`GET /work-items/vocabulary` exists now (behind `work_item.view`) and both
screens read it. The create page's copy is gone.

## Consequences

- Four routes, one screen (`/settings/templates`) and one picker (on
  `/work/new`), shipped together — the reachability guard sees a caller for
  each verb.
- **`create_work_item(template)` stays out of `ActionExecutor`.** ADR 0014's
  reason is unchanged: each created item starts a new causation chain, so ADR
  0002's recursion guard cannot see a rule that creates work which triggers the
  rule. That action now has something to name; it still needs a bound before
  it needs a form.

Still owed, and named here so they are not assumed:

- **Project-scoped templates**, and letting a project's owner write them. The
  permission is organization-wide in this slice; a manager who wants a template
  for their own project asks an admin.
- **Recurrences cannot pick a template.** They keep their embedded one. The
  shapes match, so this is a join, not a migration of meaning.
- ~~**Other copies of the vocabulary remain.**~~ Paid:
  `CreateRecurrenceRequest` reads `WorkItemModel`'s constants, and every form
  that offers a priority — the recurrence form, the edit form, both project
  forms and the browse filters — reads `GET /work-items/vocabulary`. Projects
  validate against the same `WorkItemModel::PRIORITIES`: one scale, carried by
  the same CHECK on both tables. (Project STATUSES are a different list, and
  the edit form still writes them out.)
- **No E2E flow.** Nobody has yet opened the picker as an employee and created
  an item from a template — the only check that finds a call that does not
  work.

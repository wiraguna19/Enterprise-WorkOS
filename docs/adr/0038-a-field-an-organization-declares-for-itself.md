# ADR 0038 — A field an organization declares for itself

- **Status:** accepted
- **Date:** 2026-09-23
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/02` §9, `docs/03` §8, `docs/05` §4, `docs/08` §5, `docs/12`

## Context

Custom fields are described in five documents. docs/02 §4 says a work item
"contains its custom field values". docs/03 §8 names them as the example of a
pure child that may `CASCADE`. docs/05 §4 publishes the filter grammar
`filter[cf_client]=Acme`. docs/08 §5 puts them in the More section of the work
item form. docs/12 counts them among what the domain model bought.

**Grepping the whole repository for `custom_field` returned nothing.** No
table, no model, no endpoint, no component, no test. Six phases.

This is the project's most repeated defect in its documentation variant. The
usual form is a permission with no endpoint or a column nothing writes, found
because a guard test learned to ask. This one had no guard to fail: every check
in the suite asks about things that exist, and nothing asks whether a paragraph
in a specification ever became code. **A specification asserting a capability is
not evidence of one** — the same sentence this codebase already wrote about a
comment, when the board's "discrete draggable objects" turned out to be
`<Link>`s.

## Decision

### Definition + value, as specified

`custom_field_definitions` (what may be asked) and `custom_field_values` (what
was answered). The two shortcuts are both refused for the reasons docs/02 §9
already gives: a JSONB column on `work_items` cannot be validated, renamed or
listed for a filter UI; runtime `ALTER TABLE` gives every tenant a different
schema.

### Typed columns, and exactly one of them per row

`value_text` / `value_number` / `value_date`, with
`CHECK (num_nonnulls(...) = 1)`. A row with two answers reads differently
depending on which column the query looks at.

**"No answer" is the absence of the row**, never a row of nulls, which is what
makes `required` checkable at all.

There is **no `value_json`**, though docs/02 §9 lists one. It belongs to the
multi-value types this slice does not ship, and a column nothing can write is a
defect this product has already paid for: `approvals.submission_note` was
written by the seed and by nothing else for four phases, so every screenshot
looked right and production was blank.

### The subject is a real foreign key — the deviation

docs/02 §9 sketches `entity_type, entity_id`. **This table instead carries a
nullable `work_item_id` and a nullable `project_id`, exactly one non-null**,
each a composite FK that `CASCADE`s.

A polymorphic pair cannot be declared to the database. Deleting a work item
would leave its answers behind, addressed to an id that no longer exists — and
this product deletes work items, so the orphan is not hypothetical. The
alternative is application code that remembers to sweep, which is exactly the
promise docs/03 §8 rule 5 declines to make anywhere else in this schema.

The cost is one nullable column per new subject type, paid at the rate the
product grows subject types, which is roughly never.

### Four types, and the boundary is deliberate

`text`, `number`, `date`, `select`. Each type costs a validator, an input, a
renderer, a filter operator and a sort order; shipping eight badly is how a
field type ends up storing "yes" as text. `multi_select` and `checkbox` arrive
with the `value_json` column and the filter semantics they need, or not at all.

`numeric(18,4)`, not a float, and it leaves the API as a **string**. Money and
quantities are why anybody adds a number field; casting to a double in the last
hop is where that loss is hardest to see, because everything upstream is right.

### The key is frozen; the label is not

`filter[cf_<key>]` is published API grammar, so a key is part of the interface
the moment it exists: renaming one breaks every saved link and every
integration. The API refuses a key change **by name**, and the screen does not
render a control for it — a control the API will refuse is the dead control this
product keeps finding, and not rendering it is cheaper than explaining it.

The type is frozen for a second reason: it decides which column the answers are
in.

### Retiring is not deleting

Retiring stops the form asking and leaves every answer on every record. Deleting
destroys the answers, and is for the one honest case — declared by mistake,
never used. They are **two controls with two words**, because one button named
for the gentler of the two eventually performs the other. The audit entry for a
deletion records how many answers it destroyed, counted *before* the cascade
removes the evidence.

### One permission, not three

`custom_field.manage` covers the definition side and nothing else.

**Reading a value is reading the record it is on; writing one is editing that
record.** A second permission in front of either would be a third answer to a
question two layers already answer, which is docs/06 §2's named recurring
failure — two layers disagreeing, with the coarse one silently winning.

## Consequences

- An organization can declare, edit, reorder, retire and delete its own fields,
  and see which ones a filter would name.
- **The answers are not yet collected anywhere.** This slice ships the
  definition side end to end — endpoints and the screen together, because an
  endpoint with no interface is the defect above — and the work item form reads
  and writes them in the slice that follows. The gap is deliberate and it is
  narrow; a field that can be declared and never answered is a dead control if
  it outlives one slice.
- `filter[cf_<key>]` is published grammar and still unimplemented. It has been
  unimplemented since Phase 2; what changes here is that the key it filters on
  now exists and the API says out loud what it would be called.
- Projects are in the same schema and reachable through the same endpoints, with
  no screen. Deliberate: the screen arrives with the project form that would
  read the fields, rather than as a tab that declares fields nothing asks.

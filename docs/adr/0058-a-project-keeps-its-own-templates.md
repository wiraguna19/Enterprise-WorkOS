# ADR 0058 — A project keeps its own templates

- **Status:** accepted
- **Date:** 2026-09-30
- **Phase:** after Phase 7 (`docs/10`, the queue, item 6)
- **Relates to:** ADR 0047, ADR 0040, ADR 0041

## Context

ADR 0047 made templates organization-wide and writable only with
`work_item_template.manage`, and named what that left owed: "a manager who
wants a template for their own project asks an admin." A project's starting
points — its release checklist, its bug report — are part of how that project
is run, and the people who run it could not keep them.

## Decision

**`work_item_templates.project_id`: null is the organization's template, set
is one project's. A project's templates are written by whoever may change the
project, read by whoever can see it, and offered on its forms.**

- **Who writes:** an organization-wide template needs
  `work_item_template.manage`, as before. A project's needs that, or
  `ProjectPolicy::update` — the project's owner and managers (ADR 0040). The
  write routes are guarded on `project.view` and the controller decides; a
  route demanding the template permission would refuse the project's manager
  before the question was asked, the coarse layer silently winning.
- **Who reads:** the list returns the organization's templates and those of the
  projects the reader can see. A template of a private project names that
  project's work; somebody not on it does not see it.
- **Names are unique per scope** — per project, and across the organization's
  own — with one expression index, because a plain UNIQUE over a nullable
  column treats every NULL as distinct and would let two organization-wide
  "Bug report"s through.
- **The project is fixed once made.** Moving a template between projects is
  deleting one and writing another; a PATCH that could move it would also be a
  way to move it into a project the writer cannot manage.
- **Where they are written:** the project's settings page, which is already
  only for the people who may change the project. The organization's
  Templates settings screen keeps the organization's own.
- **Where they are offered:** the create form opened from a project offers the
  organization's templates and that project's; opened from nowhere, every
  template the reader can see, a project's labelled with its key. Choosing a
  project's template opens the form ON that project, and the recurrence form
  likewise starts in it.

## Consequences

- Templates go with their project (`ON DELETE CASCADE`). Projects are archived
  far more often than deleted, and an archived project keeps its templates.
- A project template can be ANSWERED with the organization's custom fields
  like any other; fields are organization-wide (ADR 0038), not per project.

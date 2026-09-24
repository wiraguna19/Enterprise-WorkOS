# ADR 0043 — A project says what happened to it

- **Status:** accepted
- **Date:** 2026-09-24
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/02` §8, ADR 0040, ADR 0041

## Context

ADR 0040 and ADR 0041 added six activity verbs on projects — `updated`,
`archived`, `restored`, `member_added`, `member_removed`,
`member_role_changed` — and every one of them wrote to `activity_logs` under
`subject_type = 'project'`.

**Nothing could read a single one.** The only activity route in the product is
`GET /work-items/{reference}/activity`.

This is the defect this project has found more often than any other — a write
path with no read path — and this instance is self-inflicted: the slice that
added the writes did not add the reader, two commits ago, while its own ADR was
naming the same shape in somebody else's code.

It matters most for access. `project_members` keeps removed rows with a
`removed_at` **precisely** so "who could see this project, and when" stays
answerable. The rows were being kept and the question could not be asked.

## Decision

**`GET /projects/{key}/activity`, in `ActivityController` beside the work item
timeline**, and for the reason that controller already gives: reading a
timeline is a visibility decision about the SUBJECT, so Work resolves the
project through its own scope and policy and then asks Governance a question
that is purely about storage. Inverting it would teach the log about every kind
of subject it records.

**`since` is the project's own `created_at`**, exactly as the work item
timeline uses the item's — nothing happened to it before it existed, and
`activity_logs` is partitioned by `occurred_at`, so the bound is what keeps the
read off every partition ever created. No `?? now()->subYears(5)` fallback
here: `projects.created_at` is NOT NULL, and a coalesce whose left side cannot
be null reads as a real case.

**The timeline component gains an `emptyMessage`, not a copy.** The only thing
that differs between a project's timeline and a work item's is the noun, and
copying a component to change one word is how two timelines start rendering the
same log differently.

**Six verbs joined the label map.** They had been written for two commits and
rendered nowhere, so the map had never seen them — and its fallback prints an
unmapped verb as itself, deliberately, so a gap looks unfinished rather than
fine. That fallback is why this was invisible rather than broken.

## Consequences

- Who gained or lost access to a project, and when, is answerable through the
  product for the first time. The `removed_at` rows ADR 0041 kept now have a
  reader.
- The history lives on the project's **Settings** page, which is where every
  event it records is caused. It is not on the overview, which is about how the
  work is going rather than who changed what.
- **There is still no history for a department or a team**, both of which write
  activity entries the same way. Named here so the next instance is a bill
  rather than a discovery.

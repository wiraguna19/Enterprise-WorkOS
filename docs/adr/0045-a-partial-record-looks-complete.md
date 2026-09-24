# ADR 0045 — A partial record looks complete

- **Status:** accepted
- **Date:** 2026-09-24
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/02` §8, ADR 0043

## Context

Two gaps, found by asking the question ADR 0043 left open — *"there is still no
history for a department or a team, both of which write activity entries the
same way"*.

**Teams: a write path with no read path.** `TeamService` has recorded
`created`, `member_added` and `member_removed` since Phase 5. Nothing could
return one. Exactly the shape a project's history had, one module over.

**Departments: worse.** `DepartmentService` records `created` and `moved`. A
RENAME was recorded by nothing, because `DepartmentController::update` wrote
the model directly — `$department->forceFill($request->validated())->save()` —
without going through the service that does the recording.

So a department's history showed it created and moved, and a reader concluded
nothing else had happened. **A partial record looks complete**, and that is why
nobody would ever have reported it: the absence has no shape.

It also slipped the architecture rule. `controllers never touch the database
directly` covers the Organization namespace, but it watches the `DB` facade —
and this was Eloquent.

## Decision

**`DepartmentService::update()`**, and the controller calls it. Only changed
fields are written and only those are logged, like every other update here: a
save that reports an unchanged field turns the history into noise nobody reads,
and there is a test for the no-op case because that is the half that breaks
when somebody "simplifies" the diff later.

The entry records `from` as well as `to`. A history that says only what
something became cannot answer "what was it called before", which is the
question a rename is asked about.

**`GET /teams/{team}/activity`**, shaped exactly like the project one: the
visibility decision is about the SUBJECT, so Organization resolves the team
through its own policy and then asks Governance a question that is purely about
storage. `since` is the team's own `created_at`, because `activity_logs` is
partitioned by `occurred_at`.

**No department endpoint.** Departments have no detail page — only a list — so
an endpoint would be an endpoint with no caller, which is the defect this ADR
is paying off. The recording gap is fixed now because it is losing data now;
the reader arrives with the page that would show it.

## Consequences

- A team's page has a History section. Its page is one of the screens still
  using its own `<section>` layout rather than `Panel`; the section follows the
  local idiom rather than importing one panel into a page built another way.
- **Departments now record renames and still cannot show them.** That is a
  write path with no read path *by choice*, for one slice, and it is the honest
  order: losing the entries is permanent, not having a page yet is not.
- A test in the first draft of this slice asserted that a contractor gets 403
  from a team's history. The seed grants `team.view` to **every** role,
  viewer included, so the premise was false. Replaced with the question that is
  actually dangerous — another organization's team — and recorded here because
  **a test whose premise the fixture denies proves whatever the fixture happens
  to say.**

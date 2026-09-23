# ADR 0039 — A whitelist that is a mechanism, not a sentence

- **Status:** accepted
- **Date:** 2026-09-23
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/05` §4, ADR 0038

## Context

docs/05 §4 has said since Phase 2 that allowed filters and sorts are declared
per endpoint in a whitelist, and that **an unknown key is a 422, not silently
ignored — silent ignoring is how a client ships a broken filter nobody notices
for a month.**

`ListWorkItemsRequest` repeated that paragraph in its own docblock. Nothing
implemented it, on any endpoint. Two distinct shapes:

**The whitelist that looks like one.** `rules()` named every known key, and
every rule is `sometimes`. A key with no rule is therefore not refused — it is
never mentioned. `filter[assignee]`, one letter short of `assignee_id`,
returned everybody's work to a client that believed it had asked for one
person's. `applySort` `continue`d past an unknown column, one paragraph below
the sentence forbidding exactly that, so `sort=titel` succeeded in the default
order with no sign the request had been dropped.

**The endpoints with no validation at all.** `/people`, `/teams` and
`/projects` read `filter.*` straight off the request. Two consequences, and the
second is worse than silence:

- `filter[status]=activ` matched nothing and rendered as an organization with
  no projects. **A wrong answer that looks computed is not reported as a bug.**
- `filter[department_id]=banana` reached Postgres as a uuid comparison and came
  back a **500**. A malformed query string is the caller's mistake; answering
  500 tells them the server broke and sends them to read server logs for it.

## Decision

**One rule class in Platform, `OnlyKnownFilters`**, used by every collection
endpoint. The endpoints do not disagree about what this means — they only
disagree about which keys they answer. *Fix the class, not the instance.*

The message names the refused key and lists what is allowed, because the
commonest cause is a typo one letter long and the second is a client written
against another version. Neither is helped by "invalid filter".

**Values are validated too, not only keys.** A status is checked against the
list the CHECK constraint enforces, a uuid against being a uuid. The list moved
onto `ProjectModel::STATUSES`, beside `TYPES` and `PRIORITIES`: it had existed
only inside a migration, and *the thing that consumes a value is the thing that
should name it.*

**`cf_<key>` joins the allowed LIST rather than sitting beside it** (ADR 0038).
"What this organization can filter by" is one answer, and a whitelist with a
hole in it is a whitelist somebody widens again later. The definitions are read
only when a `cf_` key was actually sent, so a plain list request costs no extra
query — `QueryPerformanceTest` would have caught that, after somebody spent an
afternoon on why.

## Consequences

- A filter typo is now a 422 that names the key, everywhere. This is a
  **breaking change for any client sending a key that was previously ignored**
  — which is the point: such a client is already getting answers to a question
  it did not ask.
- `filter[cf_nonsense]` lost its bespoke sentence ("this organization has no
  custom field called…") and now gets the shared one. The trade is deliberate
  and it is not free: the shared message lists the `cf_` keys that DO exist, so
  somebody who mistyped `cf_client` can see the spelling that works, but the
  refusal no longer says outright that the field is missing rather than
  misspelled.
- Sorting still refuses unknown columns per endpoint rather than through a
  shared rule. Only `/work-items` accepts `sort` at all, so there is no class
  to fix yet; the day a second endpoint sorts, this is where the second
  instance goes.
- **`include` is still unenforced.** docs/05 §4 makes the same promise for it,
  and no endpoint accepts `include` yet. Written down so that the first one to
  accept it inherits a bill rather than a blank page.

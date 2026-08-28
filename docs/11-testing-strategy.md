# 11 — Testing Strategy

---

## 1. Shape of the suite

```text
        ╱╲          E2E (Playwright)        ~15 flows
       ╱  ╲         slow, brittle, high value — only for flows that,
      ╱────╲        if broken, make the product unusable
     ╱      ╲       Integration / Feature (Pest)   ~400 tests
    ╱        ╲      the centre of gravity: HTTP in, database out,
   ╱──────────╲     real authorization, real transactions
  ╱            ╲    Unit (Pest / Vitest)           ~600 tests
 ╱______________╲   pure domain logic: state machines, workload maths,
                    permission resolution, rule condition evaluation
```

The bulk of the value sits in the middle layer, deliberately. In a Laravel
application, a "unit test" of a service with everything mocked mostly asserts
that the mocks were configured correctly. A feature test that hits the real
route, the real policy, and the real database tests what actually ships.

---

## 2. Unit tests — what genuinely deserves isolation

- Workflow state machine: legal and illegal transitions, guards.
- Rule condition evaluator: the JSON predicate tree, including nested
  all/any/not and missing fields.
- Permission resolution: role union, scoped grants, precedence.
- Workload calculation: capacity, distribution across a span, unestimated items,
  part-time capacity, overlapping weeks, time off.
- Reference generation, fractional position ordering, dependency cycle detection,
  hierarchy depth limits.
- Date/timezone conversion at boundaries (the `due_at` / `start_date` split).
- Frontend: formatters, permission-block readers, Zod schema parsing.

---

## 3. Feature / integration tests — the core

Every endpoint gets, at minimum:

```php
it('creates a work item')                        // happy path, correct shape
it('returns 422 for a missing title')            // validation
it('returns 403 for an employee assigning work') // authorization denial
it('returns 404 for a work item in another org') // tenant isolation
it('writes an activity log entry')               // side effects
it('does not exceed N queries')                  // performance regression
```

Standing rule: **an authorization test that only asserts the allowed case is not
a test.** The denial case is the one that matters.

### The tenant isolation suite

Reflection-driven, so it cannot be forgotten:

```php
// discovers every model using BelongsToOrganization and asserts, for each:
//   1. querying without tenant context throws
//   2. org A cannot read, update, or delete an org B record
//   3. create fills organization_id from context and ignores client input
//   4. every route returns 404 (not 403) for a foreign-tenant resource
```

A new model that omits the trait fails this suite. That is the point.

### Architecture tests

Run in CI alongside the unit suite:

- **Deptrac**: no module imports another module's `Infrastructure` or
  `Domain/Entity`; no dependency cycles; only `Platform` is universally
  importable.
- **Pest arch presets**: controllers do not use `DB::`, models are not
  constructed in HTTP classes, no `dd`/`dump`/`ray` in committed code, every
  model is `$guarded = ['*']`, every Resource extends the base Resource.
- **Contract test**: the generated OpenAPI spec matches the committed one, or
  the build fails.

### Query-count assertions

List endpoints assert an upper bound on query count against a dataset with 50
records and populated relations. N+1 regressions are silent in review and
catastrophic at scale; this test makes them loud.

---

## 4. E2E tests — the fifteen flows

Playwright, against the seeded stack, on Chromium and WebKit, desktop and mobile
viewport.

```text
 1  Login, MFA challenge, logout, session revocation
 2  Admin: create department → team → invite person → assign role
 3  Manager: create project → add members → create milestone
 4  Manager: create work item with assignee and due date
 5  Employee: notification → open My Work → accept → start
 6  Employee: comment with @mention, attach a file
 7  Employee: submit for review
 8  Manager: request changes → employee resubmits → manager approves
 9  Reassignment mid-flight, and the history reads correctly afterwards
10  Board: drag a card between columns; verify state and activity log
11  Bulk: select five items, assign and set due date
12  Search: find a work item by reference, title, and comment text
13  Dashboard: manager sees the overdue item and the over-committed person
14  Cross-tenant: a Globex user cannot reach an Acme URL (expects 404)
15  Mobile: manager approves a submission end to end on a 375px viewport
```

Each flow asserts the resulting **data**, not just the visible text — the
activity log, the assignment rows, and the notification records are checked,
because a UI that looks right over a wrong database is the failure mode that
matters.

Accessibility assertions (`axe-core`) run against every screen these flows touch.

---

## 5. Non-functional testing

| Type | Approach |
|---|---|
| Load | k6 against list endpoints and the dashboard with 100k work items; p95 budgets from `01` §8 are the pass/fail line |
| Concurrency | Parallel transition requests on one work item assert exactly one succeeds and the other gets a 409 |
| Queue resilience | Jobs are run twice in tests to prove idempotency; failure paths assert no partial state |
| Workflow safety | A deliberately circular rule pair must terminate at the recursion guard, not exhaust the worker |
| Security | Automated: dependency audit, header assertions, CSP validation, upload of a disguised executable. Manual: a pre-launch review against the `06` §4 threat table |
| Data volume | Seed a 1M-row work items table in a nightly job and re-run the index-sensitive queries |

---

## 6. CI pipeline

```text
on: pull_request
  1  lint            Pint (PHP), ESLint, Prettier, tsc --noEmit
  2  static          PHPStan level 8, Deptrac
  3  unit            Pest unit + Vitest                (parallel)
  4  feature         Pest feature on real Postgres     (parallel, sharded)
  5  arch            architecture + tenant isolation suites
  6  contract        OpenAPI diff must be empty
  7  build           next build; bundle budget check
  8  e2e             Playwright against the compose stack  (main + release only)
  9  audit           composer audit, npm audit
```

Gates: coverage must not decrease; domain and application layers require ≥85%.
Coverage is a floor, not a goal — nobody is asked to test a getter.

**A red pipeline is never merged around.** The moment that becomes negotiable,
every test above stops being worth writing.

---

## 7. What the harnesses have actually caught

Kept as a running record, because a verification suite earns its place by the
defects it finds — not by existing. Every entry below was invisible in review
and would have shipped.

| Phase | Caught by | Defect |
|---|---|---|
| 2 | Migration run against real PostgreSQL | Self-referencing composite FKs (`departments.parent_id`, `employee_profiles.manager_profile_id`) cannot be declared inline — Postgres resolves the referenced key at `CREATE TABLE`, before the unique index exists. |
| 2 | axe-core on rendered pages | `--n-500` measured **4.45:1** — visually indistinguishable from passing, measurably failing AA. `--n-300` (1.79:1) was simultaneously being used for micro labels. |
| 3 | CHECK constraint during seeding | Generated `start_date` could fall after `due_at` for completed items; the seed violated its own schema. |
| 3 | Index shape proofs | The proofs passed on a warm database and failed on a freshly seeded one — stale planner statistics, not a schema fault. A proof whose result depends on when it runs is worse than none, so the suite now `ANALYZE`s first. |
| 3 | Screenshot review | Overdue items rendered "today · 0d late" — contradictory, and useless for the sub-day case a person can still act on. Lateness now degrades minutes → hours → days. |
| 3 | axe-core on the board | Disabled view tabs reused the borders-only token as text, failing contrast — the *same* mistake the Phase 2 entry above records. Fixed semantically (real `<button disabled>`, which is contrast-exempt and announces correctly) rather than by nudging the colour. |
| 3 | Screenshot review | 15 seed titles produced the same sentence three times in one person's list; the screen could not be evaluated because the eye stopped distinguishing rows. Expanded to 40. |
| 4 | Reading the proof's own output | A `DECLARE approval_id uuid` shadowed the column it was compared against, so `WHERE approval_id = approval_id` was always true — **the proof passed while asserting nothing**. Renamed, qualified, and given a second assertion on the row count so the same shadowing cannot silently return. |
| 4 | Running the SQL suites against a seeded database | `verify-constraints.sql` created its fixtures as `acme` / `rina@acme.test` — the demo organization's own slug and email. It therefore passed on an empty database and failed on a seeded one. The fixtures are now proof-only identifiers, and the suite is order-independent. |
| 4 | Reseeding from scratch | `DatabaseSeeder` still loaded one of the four seed files: `php artisan db:seed` produced an organization with no projects, no work, and no workflow. Every earlier run had loaded the files by hand, so nothing noticed. |
| 4 | Screenshot review | The primary action on an item in review read **"Mark blocked"** for its assignee. Correct by the rule as written — first available transition — and terrible advice. A transition reachable from any state is an escape hatch, not a step forward, and the API now says so (`is_escape_hatch`). |
| 4 | Screenshot review | The disabled Approve button explained itself as "You do not have permission to approval decide." Two faults: an event key rendered as prose, and the wrong reason of the two available. Guards now report the ROLE failure first — "Only the reviewer or approver can Approve" tells the person how the workflow works, where a missing permission key tells them about a configuration they cannot change. |
| 4 | Rendering the inbox against seeded rows | Three of the seven seeded notification types fell through to the raw event key, and rule-caused notifications with no actor rendered as "Someone · work escalated" — inventing an anonymous human for something an automation did. |
| 4 | Screenshot review | The review queue — the screen whose entire purpose is deciding — had no way to decide from it. |
| 4 | Mobile screenshot | `line-clamp-3` over a note with a blank line spent two lines on the break and left a lone "…" on the third at 390px. Whitespace is collapsed for the excerpt; the full note keeps its breaks on the item page. |
| 4 | Reading the delivered tree | An earlier `TransitionGuardTest.php` asserted an API that no longer exists — error codes (`work_item.invalid_transition` for `workflow.illegal_transition`), a flat `available-transitions` payload, and a named-rule guard model that implementation replaced. It was written before the contract settled and, never having been run, drifted silently. Its genuinely distinct cases were ported to `TransitionGraphTest`; the file was retired. |
| 4 | Running the workflow proofs a second time | The suite writes fixed-id fixtures into append-only tables, so a second run died with "duplicate key value violates approvals_pkey" — which reads like a schema fault and is not one. It now checks its own precondition and fails with the reseed instruction instead. |
| 4 | That same file's assertions | It expected creation to write an opening transition row, and the SEED contained one — but `WorkItemService::create()` did not. Demo data and produced data therefore had different shapes, and time-in-first-state had nothing to subtract from. The aspirational test was right; the code was wrong. Creation now records the opening row with `cause = 'system'`. |

**The last two entries are the most important in this table**, because they are
about the compensating strategy itself. Packagist is unreachable in the build
environment, so no PHP test in this repository has ever been executed — they
pass `php -l` and nothing more (§1). The consequence is now measured rather than
theorised: a test file drifted away from the code it tested and nobody noticed,
because nothing was watching. That is the honest cost of an unrunnable suite,
and it is why the SQL, condition, renderer, contrast, a11y, and bundle harnesses
exist — every one of them actually runs.

The silver lining is instructive too. The drifted test was wrong about the
API and *right* about the domain: creation should record its opening state, and
the running seed said so while the code did not. A test that cannot run still
carries the intent of whoever wrote it.

Three more of the Phase 4 entries deserve dwelling on. A proof that passed while
asserting nothing is the worst possible failure of a verification suite: it
spends the reader's trust and returns nothing. A proof suite that only passed on
an empty database is the same fault one step removed — its result depended on
what had run before it. And "Mark blocked" was not a bug in any line of code;
every line did what it said. It was a rule that was wrong, and only looking at
the rendered screen could reveal that.

The two Phase 3 entries below are worth dwelling on too. The board regression proves that writing a
rule down in `09` §2 did not stop the same developer repeating the mistake six
hours later — only the automated gate did. And a seed problem is a *test data*
problem that presented as a UI problem, which is the argument for seeding
realistically in the first place.

# Enterprise Work OS

A centralized platform for creating, distributing, performing, monitoring,
reviewing, approving, and analysing an organization's work.

**Status: Phase 4 (Workflow & Approvals) — complete.**
Architecture is in [`docs/`](./docs); read [`docs/README.md`](./docs/README.md)
first, and [`docs/adr/`](./docs/adr) for decisions that diverge from it.

---

## What exists today

```text
Phase 2 — Foundation
✓ Multi-tenant kernel        mandatory global scope, composite FK tenant checks
✓ Identity                   opaque revocable sessions, RBAC, permission catalogue
✓ Organization               departments (materialized path), teams, employee profiles
✓ Governance                 append-only partitioned activity + audit logs

Phase 3 — Core Work Management
✓ Work                       projects, milestones, ONE typed work_items table
✓ Assignment                 an entity with full history, not a pivot
✓ Workflow states            statuses as data, mapped to 7 fixed categories
✓ Dependencies               blocking edges with recursive cycle detection
✓ Visibility                 one query scope, 9 proofs, applied to every read
✓ Collaboration              comments, server-side markdown + mention extraction
✓ Files                      storage abstraction, presigned uploads, quarantine
✓ Frontend                   My Work, project directory, board, work item detail
✓ Seed                       2 tenants · 148 work items · 55 distinct titles

Phase 4 — Workflow & Approvals
✓ Transitions                edges with JSON guards, wildcards, required notes
✓ State history              append-only, partitioned, cause + causation chain
✓ Available transitions      one query serves the picker and the API check
✓ Rule engine                trigger → condition → action on the queue
✓ Recursion guard            threaded depth, persisted chain, self-disabling rules
✓ Rule run log               every evaluation recorded, including the misses
✓ Approvals                  submit / decide / withdraw, any_one · all_of · quorum
✓ Approver roster            who was ASKED, distinct from who has answered
✓ Notifications              preference-aware fan-out, dedupe key, unread counts
✓ Frontend                   status picker from the graph, review queue, Inbox,
                             notification preferences
```

One gap is stated rather than glossed: the **email channel and digest job are
not built**. Their preferences, schema, and settings screen are; in-app delivery
is complete. They move to Phase 5, where the scheduler they need already
arrives. See [`docs/10-roadmap.md`](./docs/10-roadmap.md).

---

## Running it

```bash
docker compose -f infra/docker/docker-compose.yml up -d

cd apps/api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve            # http://localhost:8000

php artisan queue:work       # notifications, workflow rules, scans, rollups

cd ../web
npm install
cp .env.example .env.local
npm run dev                  # http://localhost:3000
```

Sign in as any seeded account with the password `password`:

| Account | Role | Why it exists in the seed |
|---|---|---|
| `rina@acme.test` | Organization Admin | Full permission set; owns the private FIN project |
| `ahmad@acme.test` | Manager | Structural permissions, no role or audit access |
| `sarah@acme.test` | Employee | Current assignee of ENG-142; near capacity at 91% |
| `david@acme.test` | Employee | **Over-committed** at 117%; handed ENG-142 over |
| `budi@acme.test` | Employee, **part-time** | 24 h capacity — workload never assumes 40 |
| `tono@acme.test` | Viewer, **contract** | Assigned nothing: must see exactly zero work items |
| `former@acme.test` | **Revoked** | Login must fail with `auth.no_active_membership` |
| `gil@globex.test` | Other tenant | A leak is visible in ordinary manual testing |

**ENG-142** is the reference work item: it carries the full reassignment
narrative from `docs/02` §6 — David held it, handed it to Sarah with a recorded
reason, Ahmad reviews it — plus subtasks, dependencies, and a comment thread.

---

## Verifying it

```bash
cd apps/api
composer check          # pint · phpstan L8 · deptrac · pest

vendor/bin/pest tests/Feature/TenantIsolationTest.php   # the leak suite
```

```bash
cd infra/docker
npm install

# Run the real migrations against a real server, without the framework.
php verify-schema.php "pgsql:host=127.0.0.1;dbname=workos" workos workos

psql -v ON_ERROR_STOP=1 -f verify-constraints.sql        # 12 schema proofs
psql -v ON_ERROR_STOP=1 -f verify-work-constraints.sql   # 19 work domain proofs
psql -v ON_ERROR_STOP=1 -f verify-visibility.sql         #  9 visibility proofs
psql -v ON_ERROR_STOP=1 -f verify-workflow-constraints.sql # 15 workflow/approval proofs
psql -v ON_ERROR_STOP=1 -f verify-indexes.sql            #  8 index shape proofs

php verify-renderer.php                                  # 31 XSS / markdown cases
php verify-conditions.php                                # rule condition evaluator cases
```

```bash
cd apps/web
node scripts/verify-contrast.mjs                # WCAG AA on every token pair
npm run build && node scripts/verify-bundle-budget.mjs
cd ../../infra/docker && node verify-a11y.mjs   # axe on 8 screens, real data
```

### What the proofs assert

**Schema and work domain** — the database refuses what it must refuse:
cross-tenant references, a second active primary assignee, `done` without a
completion timestamp, a self-parenting item, a duplicate reference within a
tenant, a writable search vector, a deletable audit log, a deletable parent
with subtasks.

**Workflow and approvals** — a transition cannot loop a state to itself or be
configured twice for the same pair; a causation chain cannot exceed its bound; a
subject can hold only one PENDING approval while its resolved history is
unlimited; a resolved approval cannot lack a resolution timestamp; an approver
cannot be asked twice or borrowed from another tenant.

**Visibility** — the security boundary, proven per actor: a contractor with no
memberships sees exactly zero rows; a private project stays invisible even to
someone with `project.view_all`; a previous assignee keeps sight of work they
handed over; the reporting line is transitive; team membership grants project
access; and the predicate is expressible in one query, which is what makes
cursor pagination correct.

**Index shapes** — each hot query *can* use its intended index. `enable_seqscan`
is disabled deliberately: at seed size PostgreSQL correctly prefers a sequential
scan, so a naive plan assertion would fail on a healthy database and train
everyone to ignore it.

---

## Layout

```text
apps/api/app/Modules/          Platform · Identity · Organization · Governance
                               Workflow · Work · Collaboration · Files
                               Approval · Notification
  {Module}/Domain/             entities, value objects, events, exceptions
  {Module}/Application/        commands, queries, DTOs — the transaction boundary
  {Module}/Infrastructure/     Eloquent, jobs, listeners
  {Module}/Http/               controllers, requests, resources, policies
apps/web/src/
  app/                         routing and layouts only — no business logic
  features/                    where the code lives, grouped by feature
  components/ui/               design-system primitives
infra/docker/                  compose stack + every verification harness
docs/                          architecture (read 02 and 03 first)
```

---

## The five rules that matter most

1. **Every tenant-scoped model extends `TenantModel`.** No opt-out flag.
   Crossing tenants goes through `TenantContext::runAsPlatform()`, which is
   logged; the isolation suite fails CI on any other bypass.
2. **Modules talk through application services and domain events**, never
   through each other's Eloquent models or tables. Deptrac enforces it.
3. **The frontend decides nothing.** It renders from the `permissions` block the
   API returns. Hiding a button is a courtesy; the API behaves identically
   whether or not the button existed.
4. **Assignment rows are closed, never deleted.** Every valuable thing —
   reassignment history, workload over time, cycle-time analysis — falls out of
   that one decision, and a single `delete` anywhere destroys all of it.
5. **Visibility is a query scope, applied once.** Filtering after fetching
   breaks pagination and leaks row counts; implementing it per endpoint means
   the fifteenth endpoint is the one that forgets.
6. **Guards and defaults fail closed.** A guard predicate this version does not
   recognise blocks the transition rather than waving it through, and with no
   approval system bound `require_approval` refuses. A permissive default here
   disables every gate in the product and looks exactly like it is working.

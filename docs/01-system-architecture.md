# 01 — System Architecture

> Enterprise Work OS. Phase 1 deliverable. Nothing here is code; everything here
> constrains the code that follows.

---

## 1. Architectural position

This is a **modular monolith** — one Laravel API application, one Next.js web
application, one PostgreSQL database — with **hard internal module boundaries**
that are enforced by convention, static analysis, and code review.

It is explicitly *not* microservices, and that is a deliberate decision.

**Why modular monolith:**

| Force | Consequence |
|---|---|
| Work management is a highly relational domain. A single task read touches project, assignee, workflow state, approval, comments, tags. | Distributed joins would dominate the design. One database keeps reads cheap and transactions honest. |
| Strong consistency is a functional requirement. "Task assigned to Sarah" and "activity log entry" and "notification queued" must not diverge. | A single transaction boundary is worth more than independent deployability at this stage. |
| Team size at MVP is small. | Operational cost of N services is not repaid. |
| Future extraction is plausible (Notifications, Search, Reporting are the natural first candidates). | Module boundaries are drawn *now* so extraction later is mechanical, not archaeological. |

**The rule that makes this work:** modules communicate through published
interfaces (application services + domain events), never through each other's
Eloquent models, and never through each other's tables. If module A needs data
owned by module B, it calls B's service or listens to B's event. This is the
single most important architectural constraint in the project — the difference
between a modular monolith and a big ball of mud is whether this rule is
enforced, and it is enforced here by a dependency-boundary test in CI
(see `11-testing-strategy.md`).

---

## 2. Container view

```text
                          ┌──────────────────────────┐
                          │        Browser           │
                          │  Next.js App (SSR/RSC)   │
                          └────────────┬─────────────┘
                                       │ HTTPS
                       ┌───────────────┴───────────────┐
                       │                               │
            ┌──────────▼──────────┐        ┌───────────▼───────────┐
            │  Next.js server     │        │  (future) WebSocket   │
            │  - RSC data fetch   │        │  realtime gateway     │
            │  - BFF route handlers│       │  (Laravel Reverb)     │
            │  - session cookie   │        └───────────┬───────────┘
            └──────────┬──────────┘                    │
                       │ Bearer / internal token       │
            ┌──────────▼───────────────────────────────▼──────────┐
            │              Laravel API  (/api/v1)                  │
            │  HTTP layer → Application services → Domain → Infra   │
            │  Modules: Identity, Org, Work, Workflow, Collab, …    │
            └───┬──────────────┬───────────────┬───────────────┬───┘
                │              │               │               │
        ┌───────▼──────┐ ┌─────▼─────┐  ┌──────▼──────┐ ┌──────▼──────┐
        │ PostgreSQL   │ │  Redis    │  │ S3-compat   │ │ Mail / SMTP │
        │ (primary)    │ │ cache+queue│ │ object store│ │             │
        └──────────────┘ └─────┬─────┘  └─────────────┘ └─────────────┘
                               │
                       ┌───────▼────────┐
                       │ Queue workers  │
                       │ (Horizon)      │
                       │ notifications, │
                       │ workflow rules,│
                       │ search index,  │
                       │ report rollups │
                       └────────────────┘
```

### Why Next.js sits in front of the API rather than calling it from the browser

Three reasons, in order of weight:

1. **Token safety.** The session lives in an `HttpOnly`, `Secure`, `SameSite=Lax`
   cookie that JavaScript cannot read. The API token never enters the browser's
   JS heap, which removes the entire class of "XSS steals the token" attacks.
2. **Server-side rendering of dense screens.** A project board or a workload view
   is expensive to assemble. Doing it on the server means one round trip, less
   JS shipped, and a fast first paint.
3. **Response shaping.** A dashboard needs 6 aggregates. The domain-shaped REST
   API should not grow a `/dashboard` endpoint just because the UI wants one
   (see the API rule in `05-api-architecture.md`). The Next.js layer fans out
   and composes. The BFF absorbs UI-shaped concerns so the API can stay
   domain-shaped.

**Trade-off accepted:** an extra network hop and a Node process to operate. In
exchange the API stays clean and the auth story is materially safer. See
`12-risks-tradeoffs.md` §2.

---

## 3. Layering inside the API

Each module is internally layered. Dependencies point strictly inward.

```text
  ┌─────────────────────────────────────────────────────┐
  │  HTTP        Controllers, FormRequests, Resources,   │
  │              Policies, Routes                        │  ← thin
  ├─────────────────────────────────────────────────────┤
  │  Application Command/Query services, DTOs,           │
  │              Transaction boundary, Event dispatch    │  ← orchestration
  ├─────────────────────────────────────────────────────┤
  │  Domain      Entities, Value Objects, Domain Events, │
  │              Invariants, State machines, Repos (i/f) │  ← no framework
  ├─────────────────────────────────────────────────────┤
  │  Infrastructure  Eloquent models, Repo impls, Queue  │
  │              jobs, Storage adapters, External APIs   │
  └─────────────────────────────────────────────────────┘
```

Rules:

- **Controllers contain no business logic.** They validate, authorize, call one
  application service, return one resource. A controller longer than ~25 lines
  is a smell.
- **The transaction boundary is the application service**, never the controller,
  never the model. One command = one transaction.
- **Domain events are recorded inside the transaction, dispatched after commit.**
  This is why an activity log entry can never exist for a change that rolled
  back, and why a notification is never sent for work that was not saved.
- **Eloquent lives in Infrastructure.** The domain layer speaks to repository
  interfaces. In practice we are pragmatic: for simple CRUD modules (Tags,
  Settings) the Eloquent model *is* the persistence model and we do not build a
  ceremonial repository. Full domain modeling is applied where the invariants
  are real: **Work, Workflow, Approval, Assignment, Permissions**. This is a
  conscious rejection of uniform-layering-everywhere, which is the most common
  way "clean architecture" projects become unmaintainable.

---

## 4. Synchronous vs asynchronous

The dividing line: **anything the user must see the result of before the
response returns is synchronous; everything else is a queued job.**

| Synchronous (in-request) | Asynchronous (queued) |
|---|---|
| Validation, authorization | Notification fan-out (in-app rows + email) |
| Persisting the write | Workflow rule evaluation and side effects |
| Recording activity + audit rows | Search index updates |
| Emitting domain events | Report/rollup recomputation |
| Returning the updated resource | Attachment post-processing (thumbnails, virus scan) |
| | Recurring work materialization |
| | Deadline / escalation scanning (scheduled) |

Queue design:

- Named queues with separate priorities: `critical` (auth, security), `default`
  (workflow, notifications), `low` (search, reports, media).
- **Idempotency is mandatory.** Every job is safe to run twice — jobs carry a
  natural key and use `ShouldBeUnique` or a dedupe row where a repeat would be
  visible to the user (duplicate emails are the classic failure).
- Failed jobs land in `failed_jobs` and raise an alert. Notification jobs retry
  with backoff; workflow-rule jobs do not silently swallow failures, because a
  swallowed approval routing failure is a data-integrity bug wearing a costume.

---

## 5. Domain events as the module seam

Events are the mechanism that lets Work not know Notifications exists.

```text
Application service (Work module)
        │  writes inside transaction
        ├──► work_items row updated
        ├──► activity_logs row appended
        └──► records WorkItemStatusChanged
                    │
              (after commit)
                    ├──► Workflow module      → evaluate rules for this transition
                    ├──► Notification module  → who cares about this? queue delivery
                    ├──► Search module        → reindex work item
                    └──► Analytics module     → update cycle-time facts
```

Event naming: past tense, domain language, tenant-scoped payload.
`WorkItemAssigned`, `WorkSubmittedForReview`, `ApprovalDecided`,
`MemberRemovedFromOrganization`.

Events carry **IDs and the minimum scalar context**, not whole models. A
listener that needs more data queries for it. This keeps the payload
serializable, keeps events small on the wire, and stops events from becoming a
back-door coupling to another module's schema.

---

## 6. Multi-tenancy

**Chosen model: single database, shared schema, `organization_id` on every
tenant-scoped table, enforced by a mandatory global scope.**

Every request resolves exactly one *tenant context* during authentication.
Enforcement is layered, because one layer is never enough:

1. **Request layer** — `ResolveTenant` middleware sets an immutable
   `TenantContext` from the authenticated user's active organization membership.
2. **Query layer** — a `BelongsToOrganization` trait applies a global scope to
   every tenant-scoped model. There is no opt-out flag; a genuine cross-tenant
   operation (platform admin, background maintenance) must go through an
   explicit, audited `Tenancy::runAsPlatform()` block.
3. **Write layer** — the same trait fills `organization_id` on create from the
   context. Models never trust a client-supplied `organization_id`.
4. **Test layer** — a shared test suite asserts, for every tenant-scoped model,
   that a query without tenant context throws, and that org B's records are
   invisible from org A's context. This is a test that runs against a *list of
   models discovered by reflection*, so a new model that forgets the trait fails
   CI rather than leaking in production.

**PostgreSQL Row-Level Security is not enabled at MVP** but the schema is RLS-
compatible: every tenant table has a non-nullable `organization_id`, and no
tenant table is reachable except through one. Turning RLS on later is a
migration plus a session-variable setter in the DB connection — days, not a
rewrite. Documented as a deferred hardening step in `12-risks-tradeoffs.md`.

**What is *not* being built now:** per-tenant databases, tenant-aware sharding,
custom domains, tenant-level billing. Those are SaaS-productization concerns and
building them now would be exactly the over-engineering the brief warns against.

---

## 7. Environments and deployment

```text
Local           docker compose: postgres, redis, minio, mailpit, api, web, worker
CI              GitHub Actions: lint → static analysis → unit → integration → e2e
Staging         mirror of production, seeded with the demo organization
Production      api (n containers) │ worker (n) │ scheduler (1) │ web (n)
                managed Postgres (+ read replica when reporting demands it)
                managed Redis │ S3-compatible object storage │ CDN in front of web
```

Operational baseline from day one, because retrofitting these is painful:

- **Structured JSON logs** with `request_id`, `organization_id`, `user_id` on
  every line. Never log tokens, passwords, or file contents.
- **Health endpoints**: `/health/live` (process up) and `/health/ready`
  (DB + Redis reachable). Readiness gates deploys.
- **Migrations are backward compatible** — expand/contract, never a destructive
  change in the same release as the code that stops using the column.
- **Scheduler runs on exactly one instance** with a cache lock, or the deadline
  reminder job emails everyone twice.

---

## 8. Non-functional targets

These are budgets, not aspirations; they appear as assertions in tests where
feasible.

| Concern | Target |
|---|---|
| API p95 (list endpoints, 50 items) | < 200 ms |
| API p95 (detail endpoints) | < 150 ms |
| Web LCP, dashboard, cable | < 2.0 s |
| JS shipped to a first-load route | < 200 KB gzipped |
| Queries per API request | Bounded; N+1 fails the test suite |
| Max page size | 100 items, cursor-paginated beyond that |
| Board / list virtualization threshold | > 100 rows |

---

## 9. Is Laravel enough? — runtime capacity analysis

The domain is complex; the *runtime* is not. Those are different questions and
mixing them is how teams pick a language for the wrong reason.

### The load this system actually generates

```text
1,000 employees · ~40% concurrent at peak       ≈ 400 active users
each generating ~1 request per 8 s              ≈ 50 req/s sustained
peak burst (Monday 09:00 standup)               ≈ 200 req/s
```

200 req/s is not a demanding number. PHP 8.4 with OPcache serves a typical
Laravel JSON endpoint in 15–40 ms of application time; the request budget in §8
is dominated by PostgreSQL, not by PHP. Four API containers handle this with
substantial headroom, and the scaling axis is horizontal and boring.

**Where the difficulty in this system lives:** the permission model, tenant
isolation, the workflow engine, and the data model. All four are language-
agnostic design problems. A rewrite in Go or NestJS would leave every one of
them exactly as hard.

### The three places Laravel genuinely strains, and the answer for each

| Strain | Why | Answer |
|---|---|---|
| **WebSocket connections** | PHP-FPM is request-per-process; it cannot hold 400 idle sockets | Real-time runs as a *separate long-lived process*, never in the API. Laravel Reverb is the default choice (same event contracts, same auth). Above ~10k concurrent sockets per node, swap in Centrifugo (Go) behind the same broadcast interface — the app code does not change. |
| **Per-request bootstrap cost** | Laravel boots the framework on every request (~20–40 ms) | Laravel Octane (FrankenPHP) keeps the app in memory and removes it. **Not adopted on day one** — it introduces state-leakage bugs between requests, which is a real cost to pay before the latency budget demands it. It is a lever available when p95 approaches the §8 target, not a default. |
| **CPU-heavy or long-running jobs** | 100k-row exports, PDF generation, bulk recalculation | Already queued (§4), on workers sized independently of web containers. The discipline that matters is *set-based*: a deadline scan over 5M work items is one indexed SQL query dispatching chunked jobs — never a PHP loop over a result set. This is a code-review rule, not a framework limitation. |

### Where Laravel actively helps this particular design

Worth stating, because the framework is not neutral here:

- **Eloquent global scopes** are what make the mandatory tenant scope (§6)
  enforceable in one trait rather than in every query.
- **Policies and Gates** map directly onto the authorization layering in `06` §2.
- **Queues, scheduler, events, storage abstraction, mail, and validation** are
  first-class and already integrated. In a NestJS or Go stack each of these is a
  library decision, an integration, and a maintenance surface.
- **Horizon** gives queue observability for free, and this system runs a lot of
  queued work.

### Where Laravel works against it, and the mitigation

Being honest about the other side:

| Risk | Mitigation (already in these documents) |
|---|---|
| ActiveRecord invites business logic into models | Module layering, `04` §2; Pest arch tests forbid it |
| Eloquent produces N+1 queries by default | Query-count assertions on every list endpoint, `11` §3 |
| Global scopes are implicit magic and can be bypassed | No opt-out flag; reflection-driven isolation suite, `11` §3 |
| PHP's type system is weaker than TypeScript's or Go's | PHPStan level 8 in CI, DTOs everywhere, no `mixed` |
| "Laravel way" tutorials push fat controllers | Deptrac boundaries; 25-line controller guideline, §3 |

### Alternatives considered

| Stack | Honest assessment |
|---|---|
| **NestJS / TypeScript** | The strongest alternative. One language across the stack, shared types without codegen, native module and DI system. Costs: every batteries-included piece (auth, queue, storage, scheduler, mail) becomes a separate integration; Prisma and TypeORM are both weaker than Eloquent for the complex, tenant-scoped, polymorphic queries this domain requires. |
| **Go** | Best raw throughput and concurrency by a wide margin, and it would make the real-time layer trivial. Wrong trade for a CRUD- and workflow-heavy enterprise application: development velocity is materially lower, and throughput was never the constraint. |
| **.NET / Spring Boot** | Genuinely the conventional enterprise answer — strong typing, mature tooling, excellent for this domain. Rejected on velocity and ecosystem familiarity, not on capability. If the team's background were C# or Java, this would be the recommendation. |

**Conclusion: Laravel stays.** The framework is not the constraint at this scale,
and the three strain points each have a defined, non-disruptive answer. The
project's real risks are scope creep in the workflow engine and erosion of module
boundaries (`12` §7, §10) — neither of which a different language would fix.

**Trigger to revisit:** sustained > 1,000 req/s after horizontal scaling and
Octane, or a single module whose latency profile is irreconcilable with the rest
(the extraction path in `12` §1 covers this without a rewrite).

---

## 10. What this architecture deliberately does not do yet

Named explicitly so nobody "helpfully" adds them:

- No GraphQL. REST + a composing BFF covers the need; GraphQL's cost is in
  authorization complexity, which is precisely where this domain is hardest.
- No event sourcing. Activity log + audit log give the auditability that was
  actually asked for, at a fraction of the cost.
- No CQRS with separate read stores. Read models are Postgres views/queries
  until measurement says otherwise.
- No Elasticsearch. Postgres full-text with a `tsvector` column and GIN index
  serves the first several million rows; the Search module's interface is
  written so swapping the driver is a class, not a project.
- No microservices, no service mesh, no Kubernetes operators.

Every one of these has a documented trigger condition in `12-risks-tradeoffs.md`
that would justify revisiting it.

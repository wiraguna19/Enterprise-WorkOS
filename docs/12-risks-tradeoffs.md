# 12 — Risks & Trade-offs

> Every architecture buys something and pays for it somewhere. This document is
> the receipt.

---

## 1. Modular monolith instead of services

**Bought:** one transaction boundary, cheap joins, one deploy, one place to
debug, a team of two or three can move fast.

**Paid:** the whole application scales together; a heavy report competes with
interactive requests for the same PHP workers; module boundaries survive only as
long as the discipline that enforces them.

**Mitigation:** Deptrac in CI makes boundary erosion a build failure rather than
a judgement call. Queue workers are separate processes, so background load is
already isolated from request load. Reporting reads move to a replica before
they become a problem.

**Trigger to revisit:** sustained request-queue saturation that scaling
horizontally does not fix, or a module whose deploy cadence genuinely diverges
from the rest. Notifications and Search are the pre-designated first extractions.

---

## 2. Next.js BFF in front of the API

**Bought:** the API token never reaches browser JavaScript; server-rendered dense
screens; a place to compose UI-shaped responses without polluting the domain API.

**Paid:** an extra hop of latency; a Node process to operate and monitor; two
languages in one product; the temptation to leak business logic into the BFF.

**Mitigation:** the BFF may compose, cache, and shape — it may not decide.
Any authorization or business rule found there is a review rejection. Co-located
deployment keeps the hop at single-digit milliseconds.

**Trigger to revisit:** if the BFF starts accumulating rules despite review, the
honest answer is either to move them into the API or to admit the frontend needs
its own service — but not to let it drift into being a second backend.

---

## 3. One typed `work_items` table

**Bought:** assignment, comments, attachments, activity, workflow, custom fields,
search, and dependencies implemented once and inherited by every future work
type; cross-type queries ("everything on my plate") are trivial.

**Paid:** a wider table than any single type needs; nullable columns that only
apply to some types; a real risk of type-specific logic creeping into the core.

**Mitigation:** `type` is CHECK-constrained; type-specific columns go into 1:1
extension tables from the first time one is needed; a type registry class holds
per-type behaviour so it never becomes a `switch` in a service.

**Trigger to revisit:** if a single type accumulates more than ~8 columns nobody
else uses, that type gets an extension table — not a fork of the core.

**This is the one deviation from the literal brief** (`02` §2) and it should be
confirmed before Phase 3 begins.

---

## 4. Application-layer tenant isolation, no RLS at MVP

**Bought:** simple local development, simple tests, no per-connection session
variables, no surprising behaviour in migrations and queue workers.

**Paid:** the boundary is code. One model missing a trait, one raw query, one
`withoutGlobalScopes()` in a hurry — and data crosses tenants. This is the
highest-severity risk in the entire design.

**Mitigation:** mandatory trait with no opt-out flag; composite `(organization_id,
id)` foreign keys so the *database* rejects cross-tenant references; the
reflection-driven isolation suite; 404-not-403 across tenants; a second seeded
tenant present in every environment so a leak is visible during ordinary manual
testing.

**Trigger to revisit:** the first paying external tenant, any compliance
requirement, or any near-miss found in review — whichever comes first. RLS is
scoped as a Phase 7 item, not an open question.

---

## 5. Denormalised `state_category` on `work_items`

**Bought:** every list, count, dashboard, and overdue check filters without a
join, on an index that actually gets used.

**Paid:** two sources of truth that can drift.

**Mitigation:** written only inside the same transaction as the state change,
through a single service; a consistency test asserts no row disagrees with its
workflow state; a nightly job reports drift.

---

## 6. Cursor pagination by default

**Bought:** stable pages under concurrent writes, constant-time deep paging.

**Paid:** no page numbers, no "jump to page 40", no total count without an extra
query.

**Mitigation:** users of work tools filter and search; they do not page to 40.
Total counts are provided where genuinely needed (reports), estimated above a
threshold.

---

## 7. Configurable workflows from Phase 4

**Bought:** customers can model their own process; states are data; a renamed
state does not break a dashboard.

**Paid:** substantially more complexity than a status enum. Every status read is
a lookup; workflow versioning is a real burden; the rule engine is a small
interpreter with all the hazards that implies (loops, ordering, partial failure).

**Mitigation:** state *categories* keep generic UI and analytics simple;
workflows are versioned so history stays valid; the rule engine has a hard
recursion guard, an execution log, and a dry-run mode; the visual builder is
deferred to Phase 7 while the data model exists from Phase 4.

**Honest assessment:** this is the second-largest risk in the project. Workflow
engines are where products of this kind commonly stall. The mitigation is to
ship the *engine* narrow — six trigger types, a closed set of actions — and
resist requests to make it general.

---

## 8. Deriving progress and workload rather than storing them

**Bought:** numbers that are always consistent with the underlying work; no
stale counters to reconcile.

**Paid:** expensive aggregate queries on hot screens.

**Mitigation:** cached in `progress_cache` / snapshot tables, refreshed by queued
jobs, with the freshness timestamp shown in the UI. A number that is 90 seconds
old and labelled as such is better than a number that is wrong and confident.

---

## 9. Postgres full-text search instead of a search engine

**Bought:** no extra infrastructure, transactional consistency between data and
index, one fewer thing to operate.

**Paid:** weaker relevance ranking, no fuzzy matching or typo tolerance, no
aggregation-heavy faceting, degradation somewhere in the low millions of rows.

**Mitigation:** the Search module exposes a driver interface from day one; the
Postgres implementation is one class. Swapping to Meilisearch or OpenSearch is a
week, not a project.

**Trigger to revisit:** p95 search above 500 ms, or a user-visible relevance
complaint that ranking tweaks cannot fix.

---

## 10. Product and process risks

| Risk | Impact | Mitigation |
|---|---|---|
| **Scope creep into a generic no-code platform.** The workflow engine invites "just make it configurable" for everything. | Fatal — the product never ships | Every configurability request is an ADR with a named customer need. Default answer is no. |
| **The UI drifts into generic SaaS.** Under deadline pressure, screens get built as card grids. | The stated primary goal is missed | The `09` §8 quality gate is a merge requirement, not a suggestion. |
| **Notification fatigue.** A system that notifies on everything is muted, and then approvals are missed. | Core workflow silently fails | Granular preferences from Phase 4; digest by default for low-signal events; never notify someone about their own action. |
| **Workload numbers become a performance ranking.** | Cultural damage, and the data gets gamed | `02` §11 definitions are visible in the UI; no composite score; reports show distribution, never leaderboards. |
| **Phase 2 gets rushed** because it has nothing to demo. | Every later phase pays interest | Phase 2 exit criteria are hard gates. |
| **Test suite rots** once E2E tests get flaky. | The safety net disappears exactly when the codebase is large enough to need it | Flaky tests are quarantined and fixed within one sprint, never disabled and forgotten. |
| **Permission model outgrows RBAC.** A customer wants "can edit tasks in their own department only". | Painful retrofit | `scoped_role_assignments` exists from Phase 2 and is unused until needed — the migration of live data is the expensive part, and it is already paid. |
| **Single developer / small team bus factor.** | Project stalls | These documents are the mitigation. Every non-obvious decision here is written down with its reasoning, so the next person does not have to guess. |

---

## 11. Deliberately deferred, with trigger conditions

| Deferred | Revisit when |
|---|---|
| PostgreSQL RLS | First external tenant, or any compliance requirement |
| External search engine | p95 search > 500 ms, or relevance complaints |
| Read replica for reporting | Report queries visibly affect interactive p95 |
| Event sourcing | Never, unless a regulator requires full state reconstruction |
| CQRS with separate read stores | A read model that cannot be served by an indexed query or a materialized view |
| Microservice extraction | A module with genuinely divergent scaling or deploy needs |
| Real-time collaborative editing | Never in this product — it is a different product |
| Native mobile apps | Push notification requirements the web cannot meet |
| Portfolio / programme management | An actual customer with more than ~50 concurrent projects |
| Billing and subscriptions | The decision to sell this as SaaS |

---

## 12. The three decisions most expensive to reverse

Ranked by cost of change, so they get the most scrutiny before Phase 2 begins:

1. **`work_items` as a single typed core** (`02` §2) — reversing this after
   Phase 3 means migrating every work row, every assignment, every comment, and
   every activity entry.
2. **Tenant column on every table** — cheap now, close to a rewrite later.
   Included even though the MVP is single-company, precisely for this reason.
3. **Assignment as an entity with history** — retrofitting history onto a pivot
   table means the history before the change simply does not exist. It cannot be
   backfilled.

Everything else in these documents can be changed in weeks. These three cannot.

# Enterprise Work OS — Architecture Documentation

**Phase 1 deliverable.** Architecture, domain model, and design decisions for an
enterprise Work Management Platform. No implementation code yet — by design.

---

## Documents

| # | Document | What it decides |
|---|---|---|
| 01 | [System Architecture](./01-system-architecture.md) | Modular monolith, container view, layering, sync vs async, tenancy strategy, deployment, non-functional budgets, **runtime capacity analysis (§9: is Laravel enough?)** |
| 02 | [Domain Model](./02-domain-model.md) | Bounded contexts, aggregates and invariants, the Work Item model, assignment as an entity, workflow as data, ubiquitous language |
| 03 | [Database Schema & ERD](./03-database-schema.md) | Full ERD (Mermaid), every table, keys, indexes, constraints, integrity rules |
| 04 | [Module Structure](./04-module-structure.md) | Repository layout, module anatomy, dependency rules, frontend structure |
| 05 | [API Architecture](./05-api-architecture.md) | Endpoint map, envelopes, errors, filtering grammar, write semantics, uploads, rate limits |
| 06 | [Auth & Authorization](./06-auth-and-authorization.md) | Session model, RBAC, permission catalogue, visibility rules, security controls, threat model |
| 07 | [Frontend Architecture](./07-frontend-architecture.md) | Stack, RSC strategy, data flow, caching, performance, accessibility, real-time |
| 08 | [UX & Navigation](./08-ux-navigation.md) | Information architecture, routes, role-adaptive Home screens, the two core flows, keyboard model, mobile |
| 09 | [Design System](./09-design-system.md) | Colour, typography, spacing, components, motion, charts, per-screen quality gate |
| 10 | [Roadmap](./10-roadmap.md) | Seven phases with exit criteria, sequencing rationale, seed data, definition of done |
| 11 | [Testing Strategy](./11-testing-strategy.md) | Test pyramid, tenant isolation suite, architecture tests, E2E flows, CI pipeline |
| 12 | [Risks & Trade-offs](./12-risks-tradeoffs.md) | What each decision costs, mitigations, trigger conditions, the three irreversible choices |

Suggested reading order for review: **02 → 03 → 01 → 12**. The domain model is
where disagreement is cheapest to resolve.

---

## The architecture in one page

**Stack.** Next.js 16 (App Router, RSC) → Laravel 13 API (`/api/v1`) →
PostgreSQL 17 + Redis + S3-compatible storage. Modular monolith, monorepo,
OpenAPI-generated typed client.

**Core idea.** `Organization → People → Work → Workflow → Resources →
Governance → Insights`. A **Work Item** is the single core entity; a *task* is
one type of it, alongside request, incident, review, approval work, and
campaign. A **Project** is a container for work, not a work item. This is what
keeps the system from being a project management tool wearing a Work OS label.

**Three decisions that shape everything else:**

1. **One typed `work_items` table.** Assignment, comments, attachments,
   activity, workflow, custom fields, and search are built once and every future
   work type inherits them for free.
2. **Assignment is an entity, not a pivot.** `work_item_assignments` keeps role,
   actor, timestamps, and closure reason — so the full reassignment narrative,
   accurate workload history, and cycle-time analytics come for free.
3. **Workflow is data, not code.** States, transitions, and rules are rows.
   Every state maps to one of seven fixed **categories**, so a customer can
   rename and add states without breaking a single dashboard or report.

**Security posture.** Opaque revocable session tokens in an HttpOnly cookie set
by the Next.js server — the token never touches browser JavaScript. Every
tenant-scoped table carries `organization_id` under a mandatory global scope,
backed by composite foreign keys and a reflection-driven isolation test suite.
Authorization is RBAC with a permission catalogue; the frontend renders from the
server's own `permissions` block and decides nothing.

---

## One decision needs your confirmation before Phase 2

The brief lists both a `work_items` table and a separate `tasks` table, while
also requiring that the architecture not be coupled to the concept of "Task".
Those instructions conflict. The resolution proposed here — **a single typed
`work_items` table, with `type = 'task'`** — is argued in full in
[02 §2](./02-domain-model.md#2-requirement-issue-raised-before-implementation).

It is the cheapest decision to reverse today and one of the three most expensive
to reverse later ([12 §12](./12-risks-tradeoffs.md)). Everything downstream
assumes it.

---

## Status

```text
Phase 1  Architecture              ◼ delivered
Phase 2  Foundation                ◼ delivered
Phase 3  Core Work Management      ◼ delivered
Phase 4  Workflow & Approvals      ◼ delivered — transitions, guards, rule
                                     engine, approvals, notifications, UI.
                                     Email channel deferred to Phase 5.
Phase 5  Collaboration & Time      ◻
Phase 6  Insights                  ◻
Phase 7  Enterprise Foundation     ◻
```

Decisions that later diverge from these documents are recorded as ADRs in
`docs/adr/` — the documents are updated, never silently contradicted.

| ADR | Decision | Diverges from |
|---|---|---|
| [0001](./adr/0001-approver-roster.md) | Approvals carry an approver roster table | `03` §4 ERD |
| [0002](./adr/0002-rule-engine-recursion-guard.md) | The recursion guard is threaded; the causation chain is persisted | `02` §7 wording |

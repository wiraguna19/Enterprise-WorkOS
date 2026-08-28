# 04 — Module Structure

---

## 1. Repository layout

A single repository (monorepo), because the API contract and the client that
consumes it change together and a synchronised two-repo release is friction with
no benefit at this size.

```text
enterprise-workos/
├── apps/
│   ├── api/                    Laravel 13
│   └── web/                    Next.js 16 (App Router)
├── packages/
│   ├── api-client/             generated TS client from OpenAPI
│   ├── ui/                     design-system components
│   └── config/                 shared eslint / tsconfig / tailwind preset
├── docs/                       these documents + ADRs
├── infra/
│   ├── docker/                 local compose stack
│   └── deploy/                 environment manifests
└── .github/workflows/          CI
```

---

## 2. API module anatomy

Laravel's default `app/Http/Controllers` + `app/Models` layout does not survive
30 domain concepts. Modules live under `app/Modules/`, each self-contained:

```text
apps/api/app/Modules/Work/
├── Domain/
│   ├── Entity/            WorkItem.php, Assignment.php
│   ├── ValueObject/       Reference.php, Priority.php, DateRange.php
│   ├── Event/             WorkItemCreated.php, WorkItemAssigned.php
│   ├── Exception/         CannotCompleteBlockedWorkItem.php
│   └── Repository/        WorkItemRepository.php   (interface)
├── Application/
│   ├── Command/           CreateWorkItem/{Command,Handler}.php
│   │                      AssignWorkItem/, SubmitWorkItem/, MoveWorkItem/
│   ├── Query/             ListWorkItems/, GetWorkItemDetail/, GetMyWork/
│   └── DTO/               WorkItemData.php
├── Infrastructure/
│   ├── Eloquent/          WorkItemModel.php, EloquentWorkItemRepository.php
│   ├── Job/               RecalculateProjectProgress.php
│   └── Listener/          (subscriptions to other modules' events)
├── Http/
│   ├── Controller/        WorkItemController.php, AssignmentController.php
│   ├── Request/           CreateWorkItemRequest.php
│   ├── Resource/          WorkItemResource.php, WorkItemCollection.php
│   └── Policy/            WorkItemPolicy.php
├── Routes/                api.php
├── Database/
│   ├── Migrations/
│   └── Factories/
├── Tests/
│   ├── Unit/
│   └── Feature/
└── WorkServiceProvider.php     wiring: bindings, routes, events, policies
```

Every module has this shape. A developer who has seen one module can navigate
any of them — which is the actual point of enforced structure.

### The modules

```text
Platform/         cross-cutting kernel: TenantContext, BaseController,
                  Pagination, ApiResponse, DomainEventBus, Clock
Identity/         auth, sessions, users, roles, permissions, invitations
Organization/     organizations, departments, teams, employee profiles
Work/             projects, milestones, work items, assignments, dependencies
Workflow/         workflows, states, transitions, rules engine, recurrence
Approval/         approvals, decisions, review routing
Collaboration/    comments, mentions, reactions
Files/            file storage abstraction, attachments, upload flow
Notification/     channels, preferences, delivery, digests
Search/           indexing, query, driver abstraction
Calendar/         calendar aggregation across work sources
Governance/       activity log, audit log, settings, custom fields, tags
Insights/         dashboards, reports, workload, metrics
```

**`Approval` is separate from `Workflow`** even though they collaborate. Approval
has its own lifecycle, its own permissions, and its own reporting surface, and
it will eventually apply to subjects that are not work items (budgets, documents,
time off). Folding it into Workflow would guarantee an extraction later.

**`Platform` is the only module every other module may depend on.** It contains
no business logic.

---

## 3. Dependency rules

```text
        Insights ──────────────────────────────┐
            │                                  │
        Governance ◄──── everything writes here (via events)
            │
     ┌──────┴──────┬────────────┬─────────────┐
 Collaboration  Notification  Calendar     Search
     │              │            │            │
     └──────┬───────┴────────────┴────────────┘
            │
        Approval ──► Workflow ──► Work ──► Organization ──► Identity
                                                              │
                              all ──────────────────────► Platform
```

Enforced rules:

1. **No module imports another module's `Infrastructure/` or `Domain/Entity`.**
   The only legal cross-module imports are `Application/` service interfaces,
   `Application/DTO`, and `Domain/Event`.
2. **Downward calls are direct; upward reactions are events.** Workflow may call
   `Work`'s `TransitionWorkItem` service. Work may not call Workflow — it emits
   `WorkItemStatusChanged` and Workflow subscribes.
3. **No cycles.** Enforced by Deptrac in CI; a violating PR fails before review.
4. **Cross-module reads that would be a join go through a query service**, and
   where a join is genuinely necessary for performance (work item list needing
   assignee names), the read model is explicitly declared in `Work`'s query
   layer with a comment stating the crossing. Pragmatism, documented, not
   pragmatism, accidental.

---

## 4. Frontend module structure

```text
apps/web/src/
├── app/                          App Router — routing and layouts only
│   ├── (auth)/login/
│   ├── (app)/
│   │   ├── layout.tsx            app shell: sidebar + topbar
│   │   ├── dashboard/
│   │   ├── my-work/[view]/
│   │   ├── projects/[id]/(views)/{board,list,timeline,calendar,overview}/
│   │   ├── work/[reference]/
│   │   ├── teams/[id]/
│   │   ├── people/[id]/
│   │   ├── calendar/ · inbox/ · reports/ · settings/
│   └── api/                      BFF route handlers (session, uploads, webhooks)
├── features/                     ← where the actual code lives
│   ├── work-item/
│   │   ├── components/           WorkItemRow, WorkItemDetail, StatusPicker
│   │   ├── hooks/                useWorkItemMutations
│   │   ├── server/               server-side data functions (RSC)
│   │   ├── schemas/              zod schemas mirrored from the API contract
│   │   └── types.ts
│   ├── board/ · project/ · assignment/ · approval/ · workflow/
│   ├── comment/ · notification/ · workload/ · search/ · people/
├── components/                   app-level shared UI (not domain-aware)
├── lib/                          api client, session, permissions, formatting
└── styles/
```

**The `app/` directory holds no business logic.** A page file composes a layout
and calls into `features/`. This keeps routing decisions separate from domain
code and makes features movable.

**Feature-first, not type-first.** Grouping by `components/`, `hooks/`,
`utils/` at the top level means a single change touches five distant folders.
Grouping by feature means a change is local — the same reasoning as backend
modules, applied to the client.

---

## 5. Shared vocabulary between the two apps

The OpenAPI specification is the contract, generated from the Laravel route +
resource definitions and checked into the repo. `packages/api-client` is
generated from it (types + fetchers). A backend change that breaks the contract
fails the frontend typecheck in CI, in the same pipeline run — which is the
whole reason the monorepo is worth it.

Permission keys, state categories, priority values, and work item types live in
a **single generated constants file** shared by both apps. A renamed permission
is then a compile error, not a silently-denied action in production.

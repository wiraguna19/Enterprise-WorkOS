# 10 — Development Roadmap

> Seven phases. Each ends with something demonstrable and tested. No phase
> begins before its predecessor's exit criteria are met.

---

## Phase 1 — Architecture *(this document set)*

**Exit criteria:** the ten Phase 1 outputs reviewed and approved, and the one
open decision in `02` §2 (`work_items` vs a separate `tasks` table) resolved.

---

## Phase 2 — Foundation  *(delivered)*

Everything that later phases assume exists.

```text
Infrastructure    docker compose (postgres, redis, minio, mailpit)
                  CI: lint, PHPStan L8, Deptrac boundaries, tests
                  monorepo, shared config, OpenAPI generation + client codegen
Platform module   TenantContext, tenant trait + global scope, domain event bus,
                  API envelope, cursor pagination, error handler, request IDs
Identity          users, sessions, login/logout/refresh, password reset,
                  permissions catalogue, roles, policies, audit log writer
Organization      organizations, departments (materialized path), teams,
                  memberships, employee profiles, invitations
Frontend shell    app layout, sidebar, topbar, command palette skeleton,
                  design tokens, base component library, auth flow,
                  loading/empty/error primitives
Governance        audit_logs + activity_logs tables, append-only triggers,
                  partitioning, settings resolver
```

**Exit criteria**

- A user can log in, land on an empty Home, and see their organization.
- An admin can create departments, teams, and people, and assign roles.
- The tenant-isolation test suite passes over all tenant-scoped models.
- Deptrac reports zero boundary violations.
- OpenAPI spec generated, committed, and consumed by a typed client.

**Risk this phase carries:** it is the least visually rewarding phase and the
temptation to rush it is highest. Every shortcut taken here is paid for with
interest in Phases 4–6.

---

## Phase 3 — Core Work Management  *(delivered)*

```text
Work module       projects, project members, milestones
                  work_items (typed core), hierarchy, dependencies (cycle check)
                  work_item_assignments with full history
                  reference generation, position (fractional ordering)
                  visibility query scope
Collaboration     comments (markdown + sanitisation), mentions
Files             storage abstraction, presigned upload flow, attachments
Governance        activity log wired to every domain event
Frontend          project directory, project board (dnd, virtualized),
                  project list view (inline edit, bulk actions),
                  work item detail page + side panel, create dialog,
                  My Work views, saved views
```

**Exit criteria:** the full manager flow (create project → create work → assign →
employee sees it in My Work → comments → attaches a file) works end to end, with
an E2E test covering it. Board handles 500 items without frame drops.

---

## Phase 4 — Workflow & Approvals  *(delivered)*

> **Deviation, stated rather than made silently.** Workflow *states* moved into
> Phase 3. A work item cannot exist without a status, and a Phase 3 that
> hardcoded a status enum would have to be unpicked here — exactly the coupling
> `02` §7 exists to prevent. Phase 3 therefore ships `workflows` and
> `workflow_states` as data; everything below is unchanged.

```text
Workflow          versioning, transitions, guards, transition history,
                  available-transitions endpoint,
                  rule engine (trigger → condition → action) on the queue,
                  recursion guard, rule run log
Approval          approvals, decisions, submit / decide / withdraw,
                  policies (any_one / all_of / quorum), self-review setting
Notification      notification model, preferences, in-app delivery,
                  email channel + digest, queued fan-out, unread counts
Frontend          status picker driven by legal transitions, submit flow,
                  review screen (submission + diff + decide),
                  Inbox, notification preferences,
                  read-only workflow viewer
```

**Exit criteria:** the complete lifecycle — assign, accept, work, submit, request
changes, resubmit, approve, complete — is exercised by an integration test, and
every step of it appears correctly in the activity log and the assignment
history.

**Delivered, with two things stated rather than glossed:**

- `ApprovalLifecycleTest` walks the whole loop and asserts the trail it leaves:
  the ordered transition categories, the activity verbs, both decisions kept,
  and the assignment closed rather than deleted. A resubmission opens a NEW
  approval instead of reopening the old one — overwriting it would erase the
  fact that the work was bounced back once, which is precisely the history the
  reviewer needs on the second look.
- The email channel and the digest job are **not** delivered. The preferences
  they read are (the schema, the mutual-exclusion CHECK, and the settings
  screen), and in-app delivery is complete. Shipping an email sender that the
  sandbox cannot exercise would have produced a feature nothing had run — worse
  than a stated gap. It moves to Phase 5, where the scheduler it needs already
  arrives for recurrence.

**Debt this phase paid off:** Phase 3's primary-action button switched on
`state_category` to decide what to offer — Accept → Start → Submit → Approve.
It read well and it hardcoded one workflow into the client, which is the thing
`02` §7 exists to prevent. It now renders from the graph.

---

## Phase 5 — Collaboration & Time

```text
Calendar          aggregation across due dates, milestones, recurring work;
                  ICS export; calendar view
Search            Postgres FTS, tsvector maintenance, driver abstraction,
                  command-palette search across work/projects/people
Recurrence        RRULE-driven materialization job
Time              time entries, actual-hours rollup
Team workspace    team overview, team work, team workload
People            directory, profile, personal workload and history
Real-time         WebSocket channels: notifications, board moves, presence
Mobile            responsive pass; mobile My Work, detail, approvals
```

**Exit criteria:** a manager can complete an approval on a phone; global search
returns a work item by reference, title, and comment text in under 300 ms on
seeded data.

---

## Phase 6 — Insights

```text
Insights          workload calculation service (per 02 §11),
                  cycle-time and throughput metrics from work_item_transitions,
                  project health scoring (explainable, not a black box),
                  rollup jobs + cached snapshots
Dashboards        Employee Home, Manager Home, Organization Home
Reports           project report, team report, personal work report,
                  organization report; CSV/XLSX export via queued job
```

**Exit criteria:** every number on every dashboard traces to a documented
definition, and each is clickable through to the underlying records. A number a
user cannot drill into does not ship.

---

## Phase 7 — Enterprise Foundation

```text
Tenancy           optional PostgreSQL RLS, tenant provisioning,
                  organization switching, cross-tenant test hardening
Permissions       scoped role assignments in the UI, custom role builder,
                  explicit deny, permission audit view
Workflow          visual workflow builder, rule builder UI, templates
Extensibility     custom fields UI end-to-end, work item templates,
                  webhooks + outbound integrations, public API tokens
Security          enforced MFA policy, SSO/SAML, session policy controls,
                  data export and retention, GDPR-style deletion workflow
Scale             read replica routing for reports, search driver swap point,
                  archival strategy for closed work, partition maintenance
```

---

## After Phase 7 — the queue

There are no Phases 8–10. What remains is worked through in this order; the
last entry is deliberately last.

```text
1  Bulk select          DELIVERED (ADR 0055): select rows on /work, assign
                        and set a due date together; docs/11 flow 11 covered.
                        Status, tag, delete and undo left out, reasons there
2  Milestones UI        DELIVERED (ADR 0056): create, change and remove
                        milestones on the project overview; docs/11 flow 3
                        covered in full, including putting work into a
                        milestone from the create and edit forms
3  Hardening            DELIVERED: filter/sort/include refused wherever a
                        route does not answer them (RefuseUnansweredQuery,
                        docs/05 §4 — the per-key half was ADR 0039); axe-core
                        over 16 screens, both viewports (e2e/accessibility),
                        which darkened three state colours and a calendar
                        label; session revocation in E2E flow 1
4  SSO follow-ups       DEFERRED (2026-09-30, by decision — not forgotten):
                        DNS domain verification, ForceAuthn re-authentication,
                        JIT / SCIM provisioning, single logout (ADR 0052).
                        Password sign-in is what the product runs on today
5  Replica follow-ups   DELIVERED (ADR 0053, "Paid since"): an opt-in
                        streaming replica in docker-compose, and a lag check
                        that sends reports back to the primary when the
                        replica is behind or unreachable
6  Extensibility        webhook subscriptions per event type DELIVERED
                        (ADR 0048); recurrences that start from a template
                        DELIVERED (ADR 0047); project-scoped templates
                        DELIVERED (ADR 0058); service accounts DELIVERED
                        (ADR 0059) — item 6 complete
6a Rule triggers that   DELIVERED (ADR 0057): `approval.decided` from a
   never fired          listener; `schedule.due_soon` / `schedule.overdue`
                        from a 15-minute scan announcing each deadline once.
                        Webhooks may subscribe to all three
7  Indonesian           IN PROGRESS (ADR 0060). Decided 2026-10-02: the web
                        interface first; API refusals, notifications and
                        email later. Typed dictionaries in `src/i18n` instead
                        of next-intl, so a missing translation fails `tsc`.
                        `PATCH /auth/me` saves `users.locale`; Settings →
                        Language chooses it; dates take the locale.
                        Translate a screen completely or not at all — a
                        half-translated screen is worse than either language.
                        E2E users are pinned to `en`: the specs find controls
                        by their English names (Budi switches and is put back
                        in e2e/interface-language).
                        Translated: the shell (sidebar, bottom bar, account
                        menu, command palette, toasts), Settings index,
                        Settings → Language, sign-in, Home, My Work, Inbox
                        and the approval screen (notification sentences
                        come from the API and stay English), the work item
                        page with all its panels (workflow state names and
                        the organization's field labels are its own words
                        and are shown as written).
                        The project list, a project's overview (health,
                        milestones, the items behind each signal) and its
                        board, including the whole-column page.
                        Project settings (details, archive, templates,
                        access) and the new-project form, with Settings →
                        Templates because it shares the template editor.
                        New and edit work item (with the template picker
                        and milestone picker) and the Work browse screen
                        with its filters and bulk changes.
                        Next, one screen at a time: Calendar, Timesheet,
                        Recurring, Reports, People, Teams, Departments and
                        the rest of Settings.
```

---

## Sequencing rationale

| Question | Answer |
|---|---|
| Why is Workflow after Work rather than with it? | Work needs to exist before its lifecycle can be made configurable. Building the rule engine first means building it against imagined requirements. |
| Why is Notification in Phase 4, not Phase 3? | Its most valuable triggers are workflow events. Shipping it earlier means writing it twice. |
| Why is Search in Phase 5? | It is worthless until there is data worth searching, and its interface is stable from Phase 3 onward. |
| Why is Real-time so late? | Everything must be correct without it. Real-time on top of a correct system is an enhancement; real-time as a foundation is a debugging nightmare. |
| Why are dashboards last-but-one? | A dashboard is a view of accumulated data. Building it first produces beautiful screens showing invented numbers. |
| Why is multi-tenancy hardening in Phase 7 when the schema is tenant-aware from Phase 2? | The *schema* cost is near zero and must be paid upfront; the *operational* machinery (provisioning, RLS, switching UI) has real cost and no MVP value. |

---

## Seed data

Built in Phase 2, extended each phase, and used by every developer and every
E2E test. **Realistic, not tidy** — the seed contains overdue items, an
over-committed employee, unassigned work, a rejected submission, a long title
that tests truncation, and an empty project, because those are the states that
break interfaces.

```text
Acme Corporation  (+ a second tenant, Globex, existing solely to make
                    cross-tenant leaks visible in manual testing)

Departments   Engineering · Marketing · Finance · Operations
Teams         Frontend · Backend · QA · Marketing

People        Rina Wijaya      Organization Admin
              Ahmad Rizal      Engineering Manager
              Sarah Chen       Frontend Developer
              David Park       Backend Developer      ← over-committed, overdue
              Maya Putri       QA Engineer            ← unestimated work
              Budi Santoso     Designer
              Lisa Tan         Marketing Staff
              Tono Hartono     Viewer (contractor)

Projects      Platform Rebuild     (active, 74%, healthy)
              Website Refresh      (at risk, 41%, 3 overdue)
              Q3 Campaign          (not started, unassigned work)
              Legacy Migration     (archived)
              Internal Requests    (no project — standalone work items)

Work          ~180 work items across all types and states, including
              a full approval history with a changes-requested round trip
```

---

## Definition of done — every phase

```text
□ Feature tests cover happy path, authorization denial, and validation failure
□ Tenant isolation tested for every new tenant-scoped model
□ No N+1 (asserted by a query-count test on list endpoints)
□ OpenAPI regenerated and committed; typed client updated
□ Migrations are reversible and expand/contract safe
□ Activity and/or audit logging wired for every state change
□ Loading, empty, error, and partial states implemented
□ Keyboard operable, AA contrast, axe clean on the new screens
□ Mobile behaviour designed, not merely responsive
□ Seed data extended to exercise the new feature's edge cases
□ Screen passes the quality gate in 09 §8
□ An ADR recorded for any decision that diverges from these documents
```

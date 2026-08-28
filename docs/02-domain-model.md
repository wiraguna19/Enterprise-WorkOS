# 02 — Domain Model

> The centre of gravity of this system. Everything in the database schema, the
> API, and the UI is downstream of the decisions on this page.

---

## 1. The organising sentence

> **Organization → People → Work → Workflow → Resources → Governance → Insights**

Read as: an *Organization* structures *People*, who perform *Work*, whose
lifecycle is driven by *Workflow*, supported by *Resources*, constrained by
*Governance*, and measured by *Insights*.

Two consequences that shape everything:

1. **`Project` is not the root of the model.** A project is one *container* of
   work. Work exists that belongs to no project (an IT request, an incident, a
   recurring compliance check, a personal task).
2. **`Task` is not a type of thing; it is one *type* of Work Item.** Requests,
   approvals, incidents, reviews, and campaigns are siblings, not subclasses of
   task.

---

## 2. Requirement issue raised before implementation

The brief lists `work_items`, `tasks`, and `task_assignees` as separate tables,
while also insisting the architecture must not be coupled to "Project"/"Task".
**Those two instructions conflict**, and resolving the conflict in favour of the
second one is, I believe, what was actually intended. Building both a
`work_items` table and a parallel `tasks` table produces the worst outcome: two
places for status, two places for assignment, two audit trails, and every future
work type (Request, Incident) needing its own duplicate table with its own
duplicate comment/attachment/activity plumbing.

**Proposed resolution — one core table, typed:**

- `work_items` is the single core work entity. `work_items.type` distinguishes
  `task | request | approval_work | incident | review | campaign | operational`.
  A "task" is a `work_item` with `type = 'task'`.
- Cross-cutting behaviour (assignment, comments, attachments, activity, tags,
  custom fields, workflow state, dependencies, hierarchy) is implemented **once**
  against `work_items` and is therefore free for every future work type.
- When a type eventually needs many type-specific columns, it gets a **1:1
  extension table** (`work_item_incident_details`), not a fork of the core.
- Assignment is not a column and not a simple pivot: it is its own entity,
  `work_item_assignments`, with a role and a full history (§6).

The trade-off is honest: a single wide-ish typed table is less "pure" than
per-type tables and requires discipline to keep type-specific concerns out of
the core. The alternative — polymorphic joins across five tables for every list
query — costs far more, in both query complexity and future development time.
This is the classic single-table-inheritance vs class-table-inheritance choice,
resolved toward STI-with-escape-hatch because the shared surface here is very
large and the type-specific surface is small.

**If you disagree with this deviation, say so now** — it is the one place where
the implementation will differ from the literal table list in the brief, and it
is far cheaper to reverse today than in Phase 3.

---

## 3. Bounded contexts

```text
┌─────────────────────────────────────────────────────────────────────┐
│ IDENTITY & ACCESS                                                   │
│   User · Credential · Session · Membership · Role · Permission      │
│   Owns: who you are, which orgs you belong to, what you may do      │
├─────────────────────────────────────────────────────────────────────┤
│ ORGANIZATION                                                        │
│   Organization · Department · Team · TeamMember · EmployeeProfile   │
│   Owns: company structure, reporting lines, capacity                │
├─────────────────────────────────────────────────────────────────────┤
│ WORK                          ← the core domain                     │
│   WorkItem · WorkItemAssignment · Dependency · Milestone · Project  │
│   Owns: what must be done, by whom, by when, in what state          │
├─────────────────────────────────────────────────────────────────────┤
│ WORKFLOW                                                            │
│   Workflow · WorkflowState · Transition · Rule · Approval           │
│   Owns: how work legally moves from state to state, and what        │
│   happens automatically when it does                                │
├─────────────────────────────────────────────────────────────────────┤
│ COLLABORATION                                                       │
│   Comment · Mention · Attachment · Notification · Reaction          │
│   Owns: the human conversation around work                          │
├─────────────────────────────────────────────────────────────────────┤
│ GOVERNANCE                                                          │
│   ActivityLog · AuditLog · Setting · CustomFieldDefinition          │
│   Owns: the record of what happened and the rules of the org        │
├─────────────────────────────────────────────────────────────────────┤
│ INSIGHTS                                                            │
│   Report · Metric · WorkloadSnapshot · SearchIndex                  │
│   Owns: derived, read-only interpretation of everything above       │
└─────────────────────────────────────────────────────────────────────┘
```

**Dependency direction:** Insights → Governance → Collaboration → Workflow →
Work → Organization → Identity. Nothing points back up. Workflow may ask Work
about a work item; Work may *not* import Workflow's rule engine — it emits an
event and Workflow reacts. This is the rule that keeps a status change from
turning into a 900-line service.

---

## 4. Aggregates and invariants

An aggregate is a consistency boundary: everything inside is saved in one
transaction and its rules always hold.

### 4.1 Organization (root: `Organization`)

Contains Departments, Teams, TeamMembers, Memberships.

- A Department has at most one parent Department; the hierarchy is acyclic and
  depth-limited (guard against a self-parenting cycle on every write).
- A Team belongs to exactly one Department (nullable for cross-functional teams).
- A person may be in many Teams; exactly one membership per (user, organization).
- Removing a Membership never hard-deletes work history — assignments become
  historical, work is reassigned or flagged, never orphaned.

### 4.2 WorkItem (root: `WorkItem`)

Contains its assignments, its dependency edges, its custom field values.

Invariants enforced in the domain, not the UI:

- A WorkItem always has a valid `workflow_state_id` belonging to the workflow
  bound to its type.
- `parent_id` forms a tree, not a graph: no cycles, max depth 5. A subtask's
  project and organization always match its parent's.
- A WorkItem cannot be closed while it has open blocking dependencies or open
  required child items — unless an explicit, permissioned override is recorded
  with a reason (real organisations need the override; silent violation is
  what destroys trust in the data).
- `actual_time` is derived from time entries, never written directly.
- Dates: `start_date <= due_date` when both are present.

### 4.3 Approval (root: `Approval`)

Contains its decisions/history.

- An Approval is always attached to exactly one subject (currently a WorkItem;
  the association is polymorphic so a Project or a Document can be approvable
  later without schema change).
- Only one Approval per subject may be `pending` at a time.
- A decision is append-only: `approved`, `changes_requested`, `rejected`,
  `withdrawn`. Nothing is ever edited or deleted; a reversal is a new record.
- The requester may not be the deciding reviewer unless the org setting
  `approvals.allow_self_review` is on (default: off).

### 4.4 Workflow (root: `Workflow`)

Contains States, Transitions, Rules.

- Exactly one state is `is_initial`; at least one is `is_terminal`.
- A Transition references states within the same workflow only.
- A Workflow that is in use by any WorkItem cannot have a state deleted; it can
  be archived and superseded by a new version. **Workflows are versioned** —
  editing a live workflow creates a new version, and existing work items keep
  their version until explicitly migrated. Without this, changing a workflow
  retroactively invalidates history, which is a compliance problem.

---

## 5. The Work model in detail

```text
                          Organization
                               │
             ┌─────────────────┼─────────────────┐
             │                 │                 │
         Project           Department           Team
        (container)             │                 │
             │                  └────── people ───┘
        Milestone                        │
             │                           │
             └────────► WorkItem ◄───────┘
                        │ type: task | request | incident | review | …
                        │ parent_id → WorkItem  (subtasks, tree)
                        │ project_id → Project  (nullable)
                        │ workflow_state_id → WorkflowState
                        │
        ┌───────────────┼────────────────┬──────────────┬──────────────┐
        │               │                │              │              │
  Assignment      Dependency          Comment      Attachment    CustomFieldValue
  (role+history)  (blocks/blocked_by)     │
                                       Mention
```

### Project as container, not as work

`Project` deliberately does **not** live in the `work_items` table. Its
lifecycle, permissions, and cardinality are different: projects are few,
long-lived, membership-scoped, and act as a permission boundary. Making a
project "a work item that contains work items" is elegant on a whiteboard and
awkward everywhere else (recursive permission checks, ambiguous progress
semantics, boards that can contain themselves).

Project progress is **derived**, never stored as ground truth — it is computed
from its work items (by count, by estimate, or by weight; the method is an org
setting) and cached in a `progress_cache` column refreshed by a queued job.

### Work that belongs to no project

`project_id` is nullable and that is a feature. An IT request, a personal task,
an incident, or a compliance review is real work that must appear in workload
calculations, dashboards, and reports. Any query that assumes `project_id IS NOT
NULL` is a bug.

---

## 6. Assignment as a first-class entity

The brief is right to insist on this, and it is worth stating why. A
`task_assignees` pivot answers "who is on this now" and destroys the answer to
every interesting question: who was on it before, who reassigned it, how long
the handover took, how many times it bounced.

```text
work_item_assignments
  work_item_id, user_id
  role            assignee | reviewer | approver | watcher | collaborator
  assigned_by     user_id
  assigned_at     timestamp
  accepted_at     timestamp (nullable — did they acknowledge?)
  unassigned_at   timestamp (nullable — NULL means currently active)
  unassigned_reason
```

- **Rows are never deleted.** Unassigning sets `unassigned_at`. The current
  assignees are `WHERE unassigned_at IS NULL`; a partial unique index enforces
  "one active primary assignee per role per item" where that rule applies.
- This single table gives you the whole reassignment narrative the brief asked
  for, plus accurate workload history, plus cycle-time analytics, for free.

Lifecycle expressed against it:

```text
Manager creates work         → WorkItem created (state = initial)
Manager assigns              → assignment(role=assignee) + WorkItemAssigned event
Employee accepts / starts    → accepted_at set; transition → In Progress
Reassignment                 → old row closed (unassigned_at + reason),
                               new row opened, both visible in history
Employee submits             → transition → In Review + Approval(pending) created
Reviewer decides             → approval decision appended
   ├─ approved               → transition → Approved → Completed
   └─ changes requested      → transition → In Progress, assignment reopened
```

---

## 7. Workflow as data, not code

A status is not an enum in PHP. It is a row.

```text
Workflow (per organization, per work type, versioned)
  └── WorkflowState      key, label, category, color, order,
                         is_initial, is_terminal, requires_approval
  └── WorkflowTransition from_state → to_state,
                         guard (permission / condition JSON),
                         label ("Submit for review")
  └── WorkflowRule       trigger + condition + action
```

**State category** is the piece that makes this workable in a generic UI. Every
custom state maps to one of a fixed set of categories:

```text
backlog | todo | in_progress | in_review | blocked | done | cancelled
```

The UI, reports, and "is this overdue?" logic key off the **category**, never
the state label. That is how a customer can rename "In Review" to "QA Gate" and
add three of their own states without breaking a single dashboard.

Default seeded workflow (a *seed*, not a hardcoded constant):

```text
Backlog → Todo → In Progress → In Review → Approved → Completed
                      ▲             │
                      └──── changes requested
                 (+ Blocked, Cancelled reachable from most states)
```

### Rules: Trigger → Condition → Action

```text
TRIGGER    work_item.status_changed | work_item.assigned | work_item.created
           | approval.decided | schedule.due_in | schedule.overdue_by
           | comment.created | field.changed

CONDITION  JSON predicate tree over the subject
           { all: [ {field:"type", op:"eq", value:"task"},
                    {field:"priority", op:"in", value:["high","urgent"]} ] }

ACTION     notify(recipients) | assign(user|role) | transition(state)
           | create_approval(reviewer) | set_field(field,value)
           | escalate(to_manager) | create_work_item(template)
           | webhook(url)   [Phase 7]
```

Executed by a queued `EvaluateWorkflowRules` job, with a **recursion guard**: a
rule-caused change carries a causation chain, and the engine refuses to process
a chain deeper than N (default 5). Without this, two rules that trigger each
other take down the queue — this is the single most common way workflow engines
fail in production, and it is cheap to prevent on day one.

---

## 8. Governance: two logs, on purpose

They look similar and must never be merged.

| | Activity Log | Audit Log |
|---|---|---|
| Audience | End users, in the UI timeline | Security, compliance, admins |
| Content | Domain narrative: "Sarah submitted work" | Security events: login, role change, permission grant, export, deletion |
| Scope | Per subject (work item, project) | Per organization, cross-cutting |
| Retention | Follows the subject | Long, independent, policy-driven |
| Mutability | Append-only | Append-only, and **write-restricted at the DB grant level** |
| Deletion | Cascades if subject is purged | Never cascades. Survives the subject. |

Both are append-only: no `UPDATE`, no `DELETE`, enforced with a Postgres trigger
rather than trusting application discipline. Activity entries store a
denormalised actor name snapshot so a deleted user's history still reads
correctly.

---

## 9. Custom fields and tags

Custom fields use a **definition + value** model, not a JSON blob and not
runtime `ALTER TABLE`:

```text
custom_field_definitions   org, scope(work_item|project), type, key, label,
                           config JSON (options, validation), required, order
custom_field_values        definition_id, entity_type, entity_id,
                           value_text / value_number / value_date / value_json
```

Typed columns rather than a single stringly-typed `value` because filtering and
sorting on a custom field is a *guaranteed* future requirement, and casting text
to date in a WHERE clause across millions of rows is not survivable. Indexed per
type. A JSONB column on `work_items` was considered and rejected: it cannot be
validated, cannot be renamed safely, and cannot be listed for a filter UI.

Tags are simple, org-scoped, and polymorphic via `taggables`.

---

## 10. Ubiquitous language

Fixed vocabulary — used identically in code, API, database, and UI. Drift here
is how a codebase stops making sense.

| Term | Meaning | Not |
|---|---|---|
| **Work Item** | Any unit of work | "Task" (that's one type) |
| **Project** | A container that groups work items | A work item |
| **Milestone** | A dated checkpoint within a project | A status |
| **Assignment** | A time-bounded link of a person to work in a role | A column |
| **Workflow State** | An org-defined status row | An enum |
| **State Category** | The fixed semantic bucket a state maps to | The state itself |
| **Approval** | A formal decision request on a subject | A comment |
| **Submission** | The act of moving work into review | A file upload |
| **Membership** | A user's belonging to an organization, with a role | A user |
| **Team** | A working group people belong to | A department |
| **Department** | A structural org unit, hierarchical | A team |
| **Activity** | User-facing history of a subject | Audit |
| **Audit** | Security/compliance event record | Activity |
| **Workload** | Committed capacity over a time window | Number of open tasks |

---

## 11. Workload — defined precisely, because a vague definition is worse than none

The brief asks for workload bars. A workload bar is a number a manager will make
staffing decisions from, so its definition must be explicit and visible in the UI:

```text
capacity(user, week)   = employee_profile.weekly_capacity_hours
                         (default 40, per-person, respects part-time)
                         − approved time off in that week

committed(user, week)  = Σ estimate_hours of work items where
                           the user has an active assignment (role=assignee),
                           state category ∈ {todo, in_progress, in_review},
                           and the item's date range overlaps the week,
                         with estimate distributed across the item's span,
                         and items with no estimate counted at the org's
                         default_estimate_hours (flagged in the UI as estimated)

utilization = committed / capacity
```

Rules that keep this honest:

- Items without an estimate are **shown as such**, not silently treated as zero.
  A bar built on unestimated work is a lie, and the UI says so.
- Utilization above 100% is displayed as over-committed, not clamped.
- This is **operational capacity signal, not a performance score.** The brief is
  right to warn against employee ranking; the UI will never rank people by a
  single composite number, and reports surface distribution and outliers rather
  than leaderboards.

---

## 12. Deferred by design

Named so they are recognised as *deliberate* absences, with the schema left
compatible: time tracking beyond a simple `time_entries` table, resource/cost
management, portfolio (project-of-projects), OKR linkage, external calendar
sync, client/guest portals, forms and intake builder, SLA management,
automations marketplace.

Each is a module that plugs into the seams already defined here — none requires
reshaping the core.

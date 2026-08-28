# 03 — Database Schema & ERD

> PostgreSQL 17. The schema reflects the domain model (`02`), not the UI.

---

## 0. Conventions

| Rule | Decision |
|---|---|
| Primary keys | `uuid` (v7, time-ordered). Not auto-increment: IDs appear in URLs and would leak org size and creation order; v7 keeps B-tree locality that random v4 destroys. |
| Naming | `snake_case`, plural tables, `{singular}_id` FKs. |
| Timestamps | `timestamptz` always. Server stores UTC; presentation converts. |
| Tenant column | `organization_id uuid NOT NULL` on every tenant-scoped table, first column after `id`. |
| Soft delete | `deleted_at timestamptz NULL` only where restore is a real user need (work items, projects, comments, attachments). Not on logs, not on join tables, not on approvals. |
| Concurrency | `lock_version integer NOT NULL DEFAULT 0` on `work_items`, `projects`, `approvals` — optimistic locking; a stale write returns HTTP 409, not a silent overwrite. |
| Enum-ish columns | `varchar` + `CHECK` constraint, not Postgres `ENUM` types. Adding a value to a PG enum inside a transaction is painful; a CHECK is a one-line migration. |
| Money | `numeric(15,2)` + separate `currency char(3)`. Never floats. |
| JSON | `jsonb` only for genuinely schemaless config (rule conditions, field config, notification payloads). Never for queryable business data. |
| Deletion of people | Users are **never** hard-deleted. Membership is revoked, the user is deactivated, history is preserved. |

**Every foreign key that crosses into another table also carries the tenant
check.** Composite FKs on `(organization_id, id)` are used on the high-risk
relationships (work item → project, work item → parent, assignment → work item)
so the *database itself* refuses a cross-tenant reference. This costs a
composite unique index per parent table and is worth it.

---

## 1. Identity & Access

```mermaid
erDiagram
    ORGANIZATIONS ||--o{ MEMBERSHIPS : has
    USERS ||--o{ MEMBERSHIPS : has
    USERS ||--o{ SESSIONS : owns
    USERS ||--o{ IDENTITY_PROVIDERS_LINKS : links
    ORGANIZATIONS ||--o{ ROLES : defines
    ROLES ||--o{ ROLE_PERMISSIONS : grants
    PERMISSIONS ||--o{ ROLE_PERMISSIONS : in
    MEMBERSHIPS ||--o{ MEMBERSHIP_ROLES : assigned
    ROLES ||--o{ MEMBERSHIP_ROLES : assigned
    MEMBERSHIPS ||--o{ SCOPED_ROLE_ASSIGNMENTS : scoped

    ORGANIZATIONS {
        uuid id PK
        varchar name
        varchar slug UK
        varchar plan
        varchar status
        jsonb settings
        timestamptz created_at
        timestamptz deleted_at
    }
    USERS {
        uuid id PK
        varchar email UK
        varchar name
        varchar password_hash
        varchar avatar_path
        varchar timezone
        varchar locale
        boolean is_platform_admin
        timestamptz email_verified_at
        timestamptz last_login_at
        varchar mfa_secret_encrypted
        timestamptz mfa_enabled_at
        timestamptz created_at
    }
    MEMBERSHIPS {
        uuid id PK
        uuid organization_id FK
        uuid user_id FK
        varchar status
        timestamptz invited_at
        timestamptz joined_at
        timestamptz revoked_at
    }
    ROLES {
        uuid id PK
        uuid organization_id FK
        varchar key
        varchar name
        boolean is_system
        integer level
    }
    PERMISSIONS {
        uuid id PK
        varchar key UK
        varchar resource
        varchar action
        varchar description
    }
    ROLE_PERMISSIONS {
        uuid role_id FK
        uuid permission_id FK
    }
    MEMBERSHIP_ROLES {
        uuid membership_id FK
        uuid role_id FK
    }
    SCOPED_ROLE_ASSIGNMENTS {
        uuid id PK
        uuid organization_id FK
        uuid membership_id FK
        uuid role_id FK
        varchar scope_type
        uuid scope_id
    }
    SESSIONS {
        uuid id PK
        uuid user_id FK
        uuid organization_id FK
        varchar token_hash UK
        varchar ip_address
        varchar user_agent
        timestamptz last_used_at
        timestamptz expires_at
        timestamptz revoked_at
    }
    IDENTITY_PROVIDERS_LINKS {
        uuid id PK
        uuid user_id FK
        varchar provider
        varchar provider_user_id
    }
```

Notes:

- **`users` is global; `memberships` is the tenant join.** A user can belong to
  several organizations — required for SaaS, and free to build now.
- `permissions` is a **global catalogue** (`work_item.assign`, `project.create`)
  seeded by migration, not per-tenant. Roles are per-tenant so a customer can
  rename or compose them.
- `scoped_role_assignments` is the seed of granular permissions: "Manager **of
  project X**", "Lead **of team Y**". At MVP only org-wide roles and project
  membership roles are used; the table exists so Phase 7 needs no migration of
  live permission data.
- Unique indexes: `memberships (organization_id, user_id)`,
  `roles (organization_id, key)`.

---

## 2. Organization structure

```mermaid
erDiagram
    ORGANIZATIONS ||--o{ DEPARTMENTS : contains
    DEPARTMENTS ||--o{ DEPARTMENTS : parent_of
    DEPARTMENTS ||--o{ TEAMS : contains
    TEAMS ||--o{ TEAM_MEMBERS : has
    MEMBERSHIPS ||--o{ TEAM_MEMBERS : joins
    MEMBERSHIPS ||--|| EMPLOYEE_PROFILES : extends
    EMPLOYEE_PROFILES ||--o{ EMPLOYEE_PROFILES : reports_to

    DEPARTMENTS {
        uuid id PK
        uuid organization_id FK
        uuid parent_id FK
        varchar name
        varchar code
        uuid head_membership_id FK
        varchar path
        integer depth
    }
    TEAMS {
        uuid id PK
        uuid organization_id FK
        uuid department_id FK
        varchar name
        varchar key
        uuid lead_membership_id FK
        varchar description
        timestamptz archived_at
    }
    TEAM_MEMBERS {
        uuid id PK
        uuid organization_id FK
        uuid team_id FK
        uuid membership_id FK
        varchar role
        timestamptz joined_at
        timestamptz left_at
    }
    EMPLOYEE_PROFILES {
        uuid id PK
        uuid organization_id FK
        uuid membership_id FK,UK
        varchar employee_number
        varchar job_title
        uuid department_id FK
        uuid manager_profile_id FK
        varchar employment_type
        numeric weekly_capacity_hours
        date hired_at
        varchar work_location
    }
```

- `departments.path` is a **materialized path** (`/root-id/child-id/`) with a
  `depth` column. Reading "everything under Engineering" is then a single
  `LIKE '/eng-id/%'` index scan instead of a recursive CTE on every dashboard
  load. The write cost (rewriting descendants on a move) is rare; the read
  benefit is on every page. `ltree` was considered — materialized path is chosen
  for portability and because Eloquent handles it without an extension.
- `employee_profiles.manager_profile_id` is the reporting line, separate from
  department headship. Cycle check on write.
- `weekly_capacity_hours` is what makes the workload calculation in `02` §11 real.

---

## 3. Work — the core

```mermaid
erDiagram
    ORGANIZATIONS ||--o{ PROJECTS : owns
    PROJECTS ||--o{ PROJECT_MEMBERS : has
    PROJECTS ||--o{ MILESTONES : has
    PROJECTS ||--o{ WORK_ITEMS : contains
    WORK_ITEMS ||--o{ WORK_ITEMS : parent_of
    MILESTONES ||--o{ WORK_ITEMS : groups
    WORK_ITEMS ||--o{ WORK_ITEM_ASSIGNMENTS : has
    MEMBERSHIPS ||--o{ WORK_ITEM_ASSIGNMENTS : receives
    WORK_ITEMS ||--o{ WORK_ITEM_DEPENDENCIES : blocks
    WORK_ITEMS ||--o{ TIME_ENTRIES : logs
    WORKFLOW_STATES ||--o{ WORK_ITEMS : states

    PROJECTS {
        uuid id PK
        uuid organization_id FK
        varchar key UK
        varchar name
        text description
        uuid owner_membership_id FK
        uuid department_id FK
        varchar status
        varchar priority
        date start_date
        date end_date
        numeric budget_amount
        char budget_currency
        numeric progress_cache
        timestamptz progress_cached_at
        uuid workflow_id FK
        integer lock_version
        timestamptz archived_at
        timestamptz deleted_at
    }
    PROJECT_MEMBERS {
        uuid id PK
        uuid organization_id FK
        uuid project_id FK
        uuid membership_id FK
        uuid team_id FK
        varchar role
        timestamptz added_at
        timestamptz removed_at
    }
    MILESTONES {
        uuid id PK
        uuid organization_id FK
        uuid project_id FK
        varchar name
        text description
        date due_date
        varchar status
        integer position
        timestamptz completed_at
    }
    WORK_ITEMS {
        uuid id PK
        uuid organization_id FK
        varchar type
        varchar reference UK
        varchar title
        text description
        uuid project_id FK
        uuid parent_id FK
        uuid milestone_id FK
        uuid created_by_membership_id FK
        uuid workflow_id FK
        uuid workflow_state_id FK
        varchar state_category
        varchar priority
        date start_date
        timestamptz due_at
        numeric estimate_hours
        numeric actual_hours_cache
        integer position
        uuid recurrence_id FK
        tsvector search_vector
        integer lock_version
        timestamptz completed_at
        timestamptz deleted_at
        timestamptz created_at
        timestamptz updated_at
    }
    WORK_ITEM_ASSIGNMENTS {
        uuid id PK
        uuid organization_id FK
        uuid work_item_id FK
        uuid membership_id FK
        varchar role
        uuid assigned_by_membership_id FK
        timestamptz assigned_at
        timestamptz accepted_at
        timestamptz unassigned_at
        varchar unassigned_reason
    }
    WORK_ITEM_DEPENDENCIES {
        uuid id PK
        uuid organization_id FK
        uuid work_item_id FK
        uuid depends_on_work_item_id FK
        varchar type
        timestamptz created_at
    }
    TIME_ENTRIES {
        uuid id PK
        uuid organization_id FK
        uuid work_item_id FK
        uuid membership_id FK
        numeric hours
        date logged_on
        varchar note
    }
```

### Key columns explained

- **`type`** — `task | request | approval_work | incident | review | campaign |
  operational`. CHECK-constrained. This is the polymorphism from `02` §2.
- **`reference`** — human-readable ID (`ENG-142`). Unique per organization,
  generated from the project key or an org-wide sequence. Users say "ENG-142" in
  Slack; a UUID is unusable for that.
- **`state_category`** — *denormalised* from `workflow_states.category`. It is
  duplicated on purpose: every list query, dashboard count, and overdue check
  filters on category, and joining `workflow_states` for all of them is a
  measurable cost. Kept in sync inside the same transaction as the state change,
  with a consistency check in the test suite.
- **`due_at` is `timestamptz`, `start_date` is `date`.** Deadlines have a time
  ("Friday 17:00"); start dates in practice do not. Mixing these types is a
  common source of off-by-one-day timezone bugs.
- **`actual_hours_cache`** — derived from `time_entries`, refreshed by job.
  Never authoritative.
- **`position`** — fractional ordering (`numeric`) for board drag-and-drop, so
  reordering one card writes one row instead of renumbering the column.
- **`search_vector`** — maintained `tsvector` over title + description, GIN
  indexed. Postgres FTS from day one, per `01` §10.

### Indexes on `work_items` (the table that decides whether the app is fast)

```sql
-- the single most important index: "my work", scoped and sorted
CREATE INDEX idx_wi_org_state_due
  ON work_items (organization_id, state_category, due_at)
  WHERE deleted_at IS NULL;

CREATE INDEX idx_wi_org_project_position
  ON work_items (organization_id, project_id, position)
  WHERE deleted_at IS NULL;

CREATE INDEX idx_wi_parent    ON work_items (parent_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_wi_milestone ON work_items (milestone_id);
CREATE INDEX idx_wi_type      ON work_items (organization_id, type);
CREATE INDEX idx_wi_search    ON work_items USING GIN (search_vector);
CREATE UNIQUE INDEX uq_wi_reference ON work_items (organization_id, reference);

-- composite target for tenant-safe FKs from child tables
CREATE UNIQUE INDEX uq_wi_org_id ON work_items (organization_id, id);

-- "what is currently on my plate" — the hottest join in the product
CREATE INDEX idx_wia_active
  ON work_item_assignments (organization_id, membership_id, role)
  WHERE unassigned_at IS NULL;

-- at most one active primary assignee per item
CREATE UNIQUE INDEX uq_wia_one_active_assignee
  ON work_item_assignments (work_item_id)
  WHERE unassigned_at IS NULL AND role = 'assignee' AND is_primary;
```

Dependency integrity: `CHECK (work_item_id <> depends_on_work_item_id)`, unique
on the pair, and cycle detection in the application via a recursive CTE before
insert (Postgres cannot express "acyclic" as a constraint).

---

## 4. Workflow & Approvals

```mermaid
erDiagram
    ORGANIZATIONS ||--o{ WORKFLOWS : defines
    WORKFLOWS ||--o{ WORKFLOW_STATES : has
    WORKFLOWS ||--o{ WORKFLOW_TRANSITIONS : has
    WORKFLOWS ||--o{ WORKFLOW_RULES : has
    WORKFLOW_STATES ||--o{ WORKFLOW_TRANSITIONS : from
    WORK_ITEMS ||--o{ WORK_ITEM_TRANSITIONS : history
    WORK_ITEMS ||--o{ APPROVALS : subject
    APPROVALS ||--o{ APPROVAL_DECISIONS : records
    WORKFLOWS ||--o{ RECURRENCES : drives

    WORKFLOWS {
        uuid id PK
        uuid organization_id FK
        varchar name
        varchar applies_to_type
        integer version
        boolean is_default
        boolean is_active
        uuid superseded_by_id FK
    }
    WORKFLOW_STATES {
        uuid id PK
        uuid organization_id FK
        uuid workflow_id FK
        varchar key
        varchar label
        varchar category
        varchar color
        integer position
        boolean is_initial
        boolean is_terminal
        boolean requires_approval
    }
    WORKFLOW_TRANSITIONS {
        uuid id PK
        uuid organization_id FK
        uuid workflow_id FK
        uuid from_state_id FK
        uuid to_state_id FK
        varchar label
        jsonb guard
        boolean requires_comment
    }
    WORKFLOW_RULES {
        uuid id PK
        uuid organization_id FK
        uuid workflow_id FK
        varchar name
        varchar trigger
        jsonb conditions
        jsonb actions
        boolean is_active
        integer run_order
    }
    WORK_ITEM_TRANSITIONS {
        uuid id PK
        uuid organization_id FK
        uuid work_item_id FK
        uuid from_state_id FK
        uuid to_state_id FK
        uuid actor_membership_id FK
        varchar cause
        uuid causation_id
        timestamptz occurred_at
    }
    APPROVALS {
        uuid id PK
        uuid organization_id FK
        varchar subject_type
        uuid subject_id
        uuid requested_by_membership_id FK
        varchar status
        varchar policy
        integer required_approvals
        text submission_note
        timestamptz submitted_at
        timestamptz resolved_at
        timestamptz due_at
        integer lock_version
    }
    APPROVAL_DECISIONS {
        uuid id PK
        uuid organization_id FK
        uuid approval_id FK
        uuid reviewer_membership_id FK
        varchar decision
        text comment
        timestamptz decided_at
    }
    RECURRENCES {
        uuid id PK
        uuid organization_id FK
        varchar rrule
        jsonb template
        timestamptz next_run_at
        timestamptz ends_at
        boolean is_active
    }
```

- `work_item_transitions` is the **state history**, separate from the activity
  log because it is structured data that cycle-time analytics query directly.
  `causation_id` links a transition to the rule execution that caused it —
  this is what makes automated changes debuggable.
- `approvals.policy` — `any_one | all_of | quorum`. `required_approvals`
  supports the quorum case. Multi-approver is modelled now even though the MVP
  UI only exposes single-reviewer, because retrofitting it means migrating live
  approval records.
- Partial unique index: one `pending` approval per subject.
- `recurrences.rrule` uses the RFC 5545 string — a solved problem, do not invent
  a scheduling DSL.

---

## 5. Collaboration, Files, Notifications

```mermaid
erDiagram
    COMMENTS ||--o{ COMMENTS : replies
    COMMENTS ||--o{ MENTIONS : contains
    ATTACHMENTS }o--|| FILES : references
    NOTIFICATIONS }o--|| MEMBERSHIPS : targets

    COMMENTS {
        uuid id PK
        uuid organization_id FK
        varchar commentable_type
        uuid commentable_id
        uuid parent_id FK
        uuid author_membership_id FK
        text body_markdown
        jsonb body_rich
        timestamptz edited_at
        timestamptz deleted_at
        timestamptz created_at
    }
    MENTIONS {
        uuid id PK
        uuid organization_id FK
        uuid comment_id FK
        uuid mentioned_membership_id FK
        uuid mentioned_team_id FK
        timestamptz read_at
    }
    FILES {
        uuid id PK
        uuid organization_id FK
        varchar disk
        varchar path UK
        varchar original_name
        varchar mime_type
        bigint size_bytes
        varchar checksum_sha256
        uuid uploaded_by_membership_id FK
        varchar scan_status
        timestamptz created_at
    }
    ATTACHMENTS {
        uuid id PK
        uuid organization_id FK
        uuid file_id FK
        varchar attachable_type
        uuid attachable_id
        integer version
        timestamptz deleted_at
    }
    NOTIFICATIONS {
        uuid id PK
        uuid organization_id FK
        uuid membership_id FK
        varchar type
        varchar subject_type
        uuid subject_id
        uuid actor_membership_id FK
        jsonb payload
        timestamptz read_at
        timestamptz archived_at
        timestamptz created_at
    }
    NOTIFICATION_PREFERENCES {
        uuid id PK
        uuid organization_id FK
        uuid membership_id FK
        varchar type
        boolean in_app
        boolean email
        varchar digest
    }
```

- **`files` and `attachments` are separate.** A file is a stored blob with a
  checksum; an attachment is a link from a subject to that file. Same file
  attached to two work items = one blob. Deduplication by checksum is then
  trivial, and versioning an attachment (`version`) does not duplicate storage.
- Binary content is **never** in the database; only the `disk` + `path`
  reference (`01`, `05`).
- `scan_status` — `pending | clean | infected | skipped`. Downloads of a
  non-clean file are refused. Uploads are quarantined until scanned.
- `notifications.payload` holds a **rendered-safe snapshot** (actor name, subject
  title at the time) so a notification list renders without N joins and still
  reads correctly after the subject is renamed or deleted.
- Notification writes are batched from a queued job; a comment mentioning 20
  people must not write 20 rows inside the user's request.

---

## 6. Governance & extensibility

```mermaid
erDiagram
    ACTIVITY_LOGS }o--|| ORGANIZATIONS : within
    AUDIT_LOGS }o--|| ORGANIZATIONS : within
    CUSTOM_FIELD_DEFINITIONS ||--o{ CUSTOM_FIELD_VALUES : defines
    TAGS ||--o{ TAGGABLES : applied

    ACTIVITY_LOGS {
        uuid id PK
        uuid organization_id FK
        varchar subject_type
        uuid subject_id
        uuid actor_membership_id FK
        varchar actor_name_snapshot
        varchar verb
        jsonb changes
        uuid correlation_id
        timestamptz occurred_at
    }
    AUDIT_LOGS {
        uuid id PK
        uuid organization_id FK
        uuid actor_user_id
        varchar actor_email_snapshot
        varchar event
        varchar target_type
        uuid target_id
        jsonb metadata
        inet ip_address
        varchar user_agent
        timestamptz occurred_at
    }
    CUSTOM_FIELD_DEFINITIONS {
        uuid id PK
        uuid organization_id FK
        varchar scope
        varchar key
        varchar label
        varchar type
        jsonb config
        boolean is_required
        integer position
        timestamptz archived_at
    }
    CUSTOM_FIELD_VALUES {
        uuid id PK
        uuid organization_id FK
        uuid definition_id FK
        varchar entity_type
        uuid entity_id
        text value_text
        numeric value_number
        timestamptz value_date
        jsonb value_json
    }
    TAGS {
        uuid id PK
        uuid organization_id FK
        varchar name
        varchar color
    }
    TAGGABLES {
        uuid tag_id FK
        varchar taggable_type
        uuid taggable_id
        uuid organization_id FK
    }
    SETTINGS {
        uuid id PK
        uuid organization_id FK
        varchar scope_type
        uuid scope_id
        varchar key
        jsonb value
    }
```

- Both log tables are protected by a `BEFORE UPDATE OR DELETE` trigger that
  raises an exception. Append-only is a database guarantee, not a code habit.
- Both are **partitioned by month** (`RANGE` on `occurred_at`) from the first
  migration. Retrofitting partitioning onto a 200-million-row table is a
  maintenance window nobody wants; doing it upfront costs one migration.
- `activity_logs.changes` stores `{field: {from, to}}` — enough to render the
  timeline without re-deriving anything.
- `correlation_id` ties every row produced by one user action together, so the
  UI can collapse "changed 4 fields" into one timeline entry.
- `settings` is hierarchical: organization → department → project → user, with
  the resolver taking the most specific value present.

---

## 7. Full table inventory

```text
IDENTITY     users · sessions · memberships · roles · permissions
             role_permissions · membership_roles · scoped_role_assignments
             identity_provider_links · password_reset_tokens · invitations

ORGANIZATION organizations · departments · teams · team_members
             employee_profiles · time_off (Phase 6+)

WORK         projects · project_members · milestones
             work_items · work_item_assignments · work_item_dependencies
             work_item_watchers · time_entries · saved_views

WORKFLOW     workflows · workflow_states · workflow_transitions
             workflow_rules · workflow_rule_runs · work_item_transitions
             approvals · approval_decisions · recurrences

COLLAB       comments · mentions · reactions
             files · attachments
             notifications · notification_preferences

GOVERNANCE   activity_logs · audit_logs · settings
             custom_field_definitions · custom_field_values
             tags · taggables

PLATFORM     jobs · failed_jobs · job_batches · cache · schedule_locks
```

`saved_views` deserves a note: user- or org-owned stored filter+grouping+sort
definitions. It is what turns a rigid page into a workspace, it is cheap, and
building filters without it means every filter combination becomes a hardcoded
page.

---

## 8. Data integrity rules summary

1. **Every multi-step write is inside a transaction.** Assignment + activity +
   event recording either all happen or none do.
2. **FKs are declared with explicit `ON DELETE` behaviour**, chosen per relation:
   `RESTRICT` for anything with history (never lose an audit trail to a cascade),
   `CASCADE` only for pure children (custom field values, taggables).
3. **Optimistic locking** on `work_items`, `projects`, `approvals`. Two managers
   editing the same task get a 409 and a merge prompt, not a lost update.
4. **Check constraints carry real rules**: `start_date <= due_at`,
   `estimate_hours >= 0`, `type IN (...)`, `state_category IN (...)`.
5. **No business logic in database triggers** — with the deliberate exception of
   append-only enforcement on the two log tables, where the whole point is that
   the application cannot be trusted.
6. **Seeded reference data** (permissions catalogue, default workflow, state
   categories) ships in migrations, not seeders, so every environment has it.

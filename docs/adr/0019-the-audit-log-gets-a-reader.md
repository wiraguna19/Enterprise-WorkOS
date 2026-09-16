# ADR 0019 — The audit log gets a reader, and only the filters its index can serve

- **Status:** accepted
- **Date:** 2026-09-16
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/03-database-schema.md` §5, `docs/06` §1, ADR 0017

## Context

`audit_logs` has been written since Phase 1 — every login failure, every
invitation, every role change, every export — and read by nothing but
`EnsureLogPartitions`. `audit_log.view` was granted to org admins in the same
migration and consulted by no route, policy or service.

`EveryPermissionMeansSomethingTest` found it the first time anything asked, as
one of ten permissions that meant nothing. It is the most expensive of the ten:
the others are features that do not exist, while this one is a complete,
correct, growing record that nobody can open. **An audit trail nobody can read
is indistinguishable from one that was never written**, and the difference only
becomes visible during the incident that needed it.

## Decision

**One endpoint, read-only.** `GET /audit-logs`, behind `audit_log.view`. There
is no write endpoint and no delete: an audit log a product can write to twice
is not an audit log, and `AuditLogger` keeps its own query-builder insert.

**Three filters: when, who, what kind.** They are exactly the three axes the
table is indexed on — `(organization_id, event, occurred_at)`,
`(actor_user_id, occurred_at)` — which is not a coincidence. `audit_logs` is
`PARTITIONED BY RANGE (occurred_at)` and grows forever; a filter the index
cannot serve (free text over `metadata`, say) reads well on a design and
sequentially scans millions of rows. A screen that offers it works in
development and times out in the one month somebody needs it most.

`event` matches as a PREFIX, because "what happened with invitations" is how
the question is actually asked, and a prefix is what a B-tree on that column
can answer.

**The actor is the email SNAPSHOT, never a resolved name.** That column exists
for this: resolving today would rewrite history every time somebody changed
their name, and would say nothing at all about an account since deleted.

**Tenant-scoped, so platform-mode rows are invisible.** `organization_id` is
nullable and `AuditLogger` writes null for events with no tenant. An
organization's audit view is its own events; rows about the platform belong to
whoever runs it, on a screen this product does not have.

**A model, used only for reading.** `CursorPage` needs a paginator, cursor
pagination is the default across this API, and the ordering carries the id
alongside `occurred_at` — a rule firing writes several events in the same
millisecond, and a cursor built from a timestamp alone cannot say which side of
itself they fall on.

**The filters live in the URL.** A filtered view is then a link somebody can
paste into an incident thread, which is most of what this screen is for.

## Consequences

- The bill in `EveryPermissionMeansSomethingTest` loses its most expensive
  entry, and its stale-entry test is what forced the list to be updated in the
  same commit.
- `AuditLogModel` is the first tenant-scoped model over a partitioned table, so
  `tests/Pest.php` gained a `minimalRowFor()` case and the isolation suite now
  probes it like every other.
- **Retention is not decided here.** The table grows forever, partitions are
  created by a command, and nothing drops them. `docs/10` Phase 7 lists "data
  export and retention" and this ADR deliberately does not pre-empt it — a
  retention window is a legal question before it is a technical one.
- Nothing about the log is redacted at READ time. `AuditLogger::redact()` does
  it at write time, and adding a second place where that is decided is how the
  two disagree.

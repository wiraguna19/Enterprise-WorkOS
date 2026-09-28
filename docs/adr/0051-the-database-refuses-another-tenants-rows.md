# ADR 0051 — The database refuses another tenant's rows

- **Status:** accepted
- **Date:** 2026-09-28
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/01` §6, `docs/12` §4, `docs/10` Phase 7, ADR 0046, ADR 0050

## Context

docs/12 §4 names application-layer tenant isolation the highest-severity risk
in the design: *"the boundary is code. One model missing a trait, one raw query,
one `withoutGlobalScopes()` in a hurry — and data crosses tenants."* It
deferred PostgreSQL Row-Level Security to Phase 7, and docs/01 §6 promised the
cost: *"a migration plus a session-variable setter in the DB connection — days,
not a rewrite."*

Two facts shaped how:

- **The connecting role is a superuser** in every environment this project has
  run in (`POSTGRES_USER` in the compose file). Superusers bypass RLS entirely,
  `FORCE` included. Policies written for "whoever connects" would do nothing
  here and something different in production.
- **Some code crosses tenants on purpose** — the scheduler, migrations,
  platform mode, the organization switcher. Each already has to say so, through
  `runAsPlatform()` or by running with no tenant bound.

## Decision

**A role, `workos_tenant`, that the connection becomes while a tenant is bound
— and policies written for that role only.**

- Every table (not partition) with a NOT NULL `organization_id` gets RLS
  enabled and one policy, `tenant_isolation`, `TO workos_tenant`,
  `USING` and `WITH CHECK` the same predicate:
  `organization_id = NULLIF(current_setting('app.organization_id', true), '')::uuid`.
  An unset organization matches nothing — fail closed.
- `TenantContext` announces every change through a new
  `Platform\Domain\Contract\TenantBoundary`: bound, re-bound for a job, entered
  or left platform mode, reset. `PostgresRowLevelSecurity` answers with
  `set_config('app.organization_id', …)` and `SET ROLE workos_tenant`, or
  `RESET ROLE` when there is no tenant. A fresh context announces "no tenant"
  as it is created, so a connection a worker reuses never starts a job wearing
  the last job's organization.
- **Everything that crosses tenants keeps working without a list of
  exceptions**, because the policies do not apply to the connecting role: no
  tenant bound means no `SET ROLE`. The two places that crossed tenants *inside*
  a bound request — listing a person's organizations and resolving the target
  of a switch (ADR 0050) — now say so through `runAsPlatform()`, which is what
  they should have done anyway.
- **Tables are found by asking the catalogue, not by a list.** The migration
  and the guard test ask the same question; the guard fails for any tenant
  table without the policy, including ones created after today — "enumerate
  what is covered" is the guard that stops guarding (ADR 0046).
- **Off unless `TENANCY_ROW_LEVEL_SECURITY=true`.** The role, grants and
  policies are installed regardless; switching it on is configuration. docs/12
  §4 says when: the first external tenant, a compliance requirement, or a
  near-miss in review.

Left out on purpose: tables whose `organization_id` is nullable. `sessions` is
how the tenant is FOUND, before any is bound; `audit_logs` holds platform events
that belong to nobody. Partitions are governed by their parent's policy when
queried through it, and `EnsureLogPartitions` creates new ones monthly.

## Consequences

- With it on, a query that forgets its tenant — a raw `DB::table()`, a
  `withoutGlobalScopes()` — sees only the bound organization's rows, cannot
  update another's, and cannot insert one. `RowLevelSecurityTest` proves each
  with queries that deliberately carry no scope, and walks a whole request
  (sign in, list, write, switch) with it on.
- **Two statements per tenant change**, only when on. Nothing is cached about
  what was last applied: `SET ROLE` is undone when its transaction rolls back,
  and a cache would eventually remember something the database has forgotten.
- **Production needs a login role that is a member of `workos_tenant`** (the
  migration grants membership to whoever runs it). A superuser works too — it
  may `SET ROLE` to anything — but the point of the exercise is a login that is
  not one.
- `workos_tenant` holds DML on every table, and — through default privileges —
  on tables created later by the role that migrates. No DDL, no BYPASSRLS.

Still owed:

- **The whole suite has not been run with it on.** The guard and the targeted
  tests run in every build; a second CI job with
  `TENANCY_ROW_LEVEL_SECURITY=true` over the entire suite is what would find
  the next cross-tenant read that forgot to say so.
- **docs/02 §8 says the audit log is "write-restricted at the DB grant level".**
  It is protected by an append-only trigger; there is no grant restriction, and
  `workos_tenant` is granted UPDATE and DELETE on it like every other table.
  The trigger still refuses both. Found while writing the grants; recorded
  here rather than fixed in passing.

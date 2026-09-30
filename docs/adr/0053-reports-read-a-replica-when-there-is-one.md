# ADR 0053 — Reports read a replica when there is one

- **Status:** accepted
- **Date:** 2026-09-28
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/01` §7, `docs/10` Phase 7, `docs/12` §1 and §11, ADR 0011, ADR 0051

## Context

docs/12 §1 names what the modular monolith costs: "a heavy report competes
with interactive requests for the same PHP workers", and promises the
mitigation — "reporting reads move to a replica before they become a problem."
docs/01 §7 draws production as "managed Postgres (+ read replica when
reporting demands it)", and docs/10 lists "read replica routing for reports"
under Phase 7 Scale.

Nothing could use a replica. Every Insights query — six query classes, five
reports, the export job — runs `DB::select()` or Eloquent against the default
connection, and there was no second connection to point at.

## Decision

**A `reporting` connection that exists only when `DB_REPORTING_HOST` is set,
and a switch that moves the DEFAULT connection to it for the length of one
read-only callback.**

- `config/database.php` adds `reporting.host/port/username/password`.
  `ReportingReplica::defineConnection()` builds the connection from the
  primary's settings with those overrides, and names it
  `application_name = workos-reporting` so `pg_stat_activity` shows whether it
  is actually in use. With no host, no connection is defined and the switch is
  a plain call.
- **The default moves; the queries do not name a connection.** Forty-odd
  queries each naming `reporting` would be forty places to forget one — and the
  forgotten one silently reads the primary. Moving the default routes all of
  them, the visibility rules and policies they call into, and whatever is
  written there next.
- **Where it applies:** every Insights read route (workload, flow, bottlenecks,
  project health, at-risk, the report catalogue and a report itself), through
  `ReadFromReportingReplica`; and the build step of `BuildReportExport`.
- **Where it does not:** the export rows. Somebody who just asked for a file
  must not be told by a lagging copy that it does not exist, and the job's
  status writes would be refused by a replica. The job switches for
  `$builder->build()` alone.
- **Safe methods only.** The middleware passes anything but GET/HEAD through to
  the primary rather than trusting every route it is attached to to be a read.
- **Row-Level Security comes along.** The tenant boundary (ADR 0051) lives in a
  database session, and the replica is a second one: `TenantContext::reassert()`
  re-announces the organization on the replica each time the switch is thrown.
  `SET ROLE` and `set_config` are accepted by a hot standby — neither is a
  write.

## Consequences

- Heavy reporting stops competing with interactive reads for the primary the
  day a replica is provisioned — configuration, not a code change.
- **Replication lag becomes visible in reports**, seconds usually. That is the
  trade docs/12 names, and it is why only computed views move: a list somebody
  just edited must never read a replica.
- The test database has no replica; `ReportingReplicaTest` builds one that
  shares the primary's session (RefreshDatabase's rows live in an open
  transaction a second session could not see) and asserts routing by which
  connection's query log a query lands in.

Paid since (2026-09-30):

- **A replica in the local compose file**, opt-in with
  `docker compose --profile replica up -d` (port 5433). It takes a base
  backup of the primary on first start and streams from then on, with no
  replication slot — a slot would keep WAL on a laptop for a replica stopped
  for weeks. The primary mounts its own `pg_hba.conf`, because the image's
  default allows replication from localhost only and init scripts never run on
  an existing volume.
- **Lag awareness, in the switch rather than a readiness endpoint.**
  `ReplicaLag` asks the replica how far behind it is — 0 when it has replayed
  everything it received (an idle primary must not make a perfect replica look
  stale), the age of its last replay otherwise, and UNKNOWN when it is not
  receiving at all — at most every 15 seconds. Further behind than
  `DB_REPORTING_MAX_LAG` (30 s), unreachable, or unknown: reports read the
  primary, and one warning is logged per window. A readiness endpoint would
  have reported the problem; this routes around it, which is what a person
  waiting on a dashboard needs.

Still owed:
- **The rollup jobs** (`work:roll-up-project-progress` and friends) still read
  the primary. They write what they compute, so they would need the same split
  the export job has.

# ADR 0021 — Retention is a dropped partition, not a delete, and the window is platform-wide

- **Status:** accepted
- **Date:** 2026-09-16
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/03-database-schema.md` §6, `docs/10` Phase 7, ADR 0019

## Context

Six tables in this schema are `PARTITION BY RANGE` on a timestamp:
`activity_logs`, `audit_logs`, `work_item_transitions`, `workflow_rule_runs`,
`approval_decisions`, `notifications`. `EnsureLogPartitions` has kept partitions
ahead of the calendar since Phase 1. **Nothing has ever removed one.**

ADR 0019 named that gap and deliberately left it open: a retention window is a
legal question before it is a technical one. `docs/10` Phase 7 lists "data
export and retention" in the Security column, and this is the retention half.

The tables were partitioned for exactly this, and the partitioning has been
carrying its cost — a composite primary key, a maintenance command, a case in
`minimalRowFor()` — while delivering only half its benefit.

## Decision

**Whole partitions are dropped. Rows are never deleted by age.** A `DELETE` over
a partitioned table of millions of rows is a long transaction, a table-sized
write to the WAL, and a vacuum afterwards. A `DROP` is a catalogue update. That
difference is the entire reason these tables are partitioned by month, and a
retention job that issued DELETEs would be paying for partitioning and then not
using it.

Two consequences follow directly, and both are accepted rather than worked
around:

- **Retention is whole months.** A partition is dropped only when its ENTIRE
  range is behind the cutoff, so rows survive up to a month past the window.
  Trimming that edge exactly means the DELETE this design exists to avoid.
- **The window is platform-wide.** A monthly partition holds every
  organization's rows for that month, so a per-organization window cannot be
  honoured by dropping. Offering one on a settings screen would be a promise
  only row-by-row deletes could keep. **Per-tenant retention is refused here and
  written down rather than implied.**

**The window lives in `config/governance.php`, one entry per partitioned table**,
in months, with `null` meaning "kept as long as the organization exists". A test
asserts the config's keys are exactly the partitioned tables in the database, so
a new partitioned table cannot be added without somebody deciding how long it
lives — which is how these six came to grow forever in the first place.

**Two tables are never pruned, and those nulls are the important entries.** A
`work_item_transition` is how an item reached its state and the input to every
cycle-time figure the product reports; an `approval_decision` is somebody's
recorded answer on a record that may still be live. Both are append-only, but
they are business records, not logs: deleting them by age would silently change
history and reported numbers rather than free space.

**Bounds are read from the catalogue, not parsed out of partition names.** The
name is this codebase's convention; `pg_get_expr(relpartbound)` is Postgres's
own truth. Anything whose bound is unrecognised — the DEFAULT partition above
all — is kept, because the safe direction for a pruner to fail in is "kept".

**The screen is told where the record ends.** `GET /audit-logs` returns the
window and the floor date in its meta, and the audit page prints it. This is the
part that would be easiest to leave out and worst to leave out: an empty result
for last March reads as "nothing happened in March", which is the most dangerous
sentence an audit log can imply, and is indistinguishable from "March was
dropped" unless the screen says so.

**Rows stranded in a DEFAULT partition are counted out loud.** If partition
maintenance stops, writes land in the default — which works, and is why a
default exists — but a default holds every month at once, so no single drop can
retire those rows and the retention promise quietly stops being kept. The
command warns with a count. A gap that is reported is a gap; one that is not is
a silence, and this codebase has paid for enough of those.

## Consequences

- Retention is enforced monthly on the 2nd, the day after partitions ahead are
  built. Both touch the same catalogue and there is no reason for them to do it
  in the same minute.
- `--dry-run` lists what would go. A command whose only mode is destructive is a
  command nobody runs by hand to check.
- **Deletion on request is NOT decided here.** "Delete this person's data"
  cannot be answered by dropping a month, and the GDPR-style workflow `docs/10`
  lists beside retention is a separate slice with a different mechanism —
  targeted, per-subject, and obliged to leave the audit record of the deletion
  itself behind.
- `EnsureLogPartitions::partitionColumn()` is now the one door into the
  partition list, so the retention config cannot name a table the maintenance
  command has never heard of.
- Changing a window changes what exists, one month at a time, on the next run.
  Shortening `audit_logs` from 24 months to 6 is eighteen dropped partitions and
  no confirmation prompt — which is an argument for it living in deployment
  config, where changes are reviewed, rather than in a settings screen.

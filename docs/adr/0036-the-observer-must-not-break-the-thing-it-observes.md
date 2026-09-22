# ADR 0036 — The observer must not break the thing it observes

- **Status:** accepted
- **Date:** 2026-09-22
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/02` §7, ADR 0014, ADR 0035

## Context

A rule card in a development environment showed **"3 recent failures"** on a
rule that had done nothing wrong, and the run log — the screen whose entire
purpose is to answer *why did it fail* — had nothing to show for any of them.

The cause was a schema one migration behind the code. The run-log insert named
`triggered_by_membership_id`, added the same afternoon (ADR 0035), and the
database did not have it yet. So every evaluation threw inside `logRun()`,
**one line after the failure counter had already been raised**. The queued job
died, the queue retried it, and each retry counted again.

Three more of those and `FAILURE_THRESHOLD` would have switched the rule off,
written a `disabled_reason` nobody could act on, and left an administrator
staring at an automation that stopped working for reasons the product had
carefully recorded nowhere. The `failed_jobs` table does not exist in this
project either, so the last trace went with it.

The schema drift was a mistake made by a person and is not interesting. What is
interesting is that the product converted an infrastructure fault into a verdict
about somebody's rule, and destroyed the evidence in the same motion.

## Decision

**A run log that cannot be written is reported and dropped.** `logRun()` catches
`Throwable`, writes `workflow.rule_run_log_failed` to the application log, and
returns. The evaluation continues, the actions stand, the job succeeds.

**And a `try` alone does not do it.** In Postgres a failed statement poisons the
whole transaction: catching the exception does not make the connection usable
again, and every statement after it is refused with "current transaction is
aborted" — so the evaluation would still die, one line further on, for a reason
even harder to read. The insert goes through `DB::transaction()`, which issues a
SAVEPOINT when one is already open, so the failure rolls back to that point and
the caller carries on. The test that takes the table away is what proved the
bare `try` insufficient; it failed on the statement AFTER the one it was
testing.

This is a real loss and worth naming: that log is how "why didn't my rule fire?"
is answered (ADR 0014 calls observability one of the three properties that
matter more than features here). Losing one line of it is still a smaller loss
than turning a missing column into a disabled automation nobody can diagnose.

**The rule is not blamed for the logger's fault.** The counter that disables a
rule after five consecutive failures now only counts failures of the RULE —
conditions, actions, the work itself.

## Consequences

- The test takes the table away with `ALTER TABLE ... RENAME` rather than
  mocking the writer. From the engine's side a missing table and an un-migrated
  column are the same event, and a mock would have asserted that the code calls
  a method rather than that the product survives the database being wrong.
- **`failed_jobs` does not exist in this project.** A queued job that dies has
  nowhere to be recorded, which is why this took a `tinker` session to find
  rather than a glance. That is its own gap and its own slice; this ADR records
  it rather than quietly fixing half of it here.
- The same shape exists anywhere a write-to-observe sits in the path of a
  write-to-do. `ActivityLogger` and `AuditLogger` are the two obvious
  neighbours, and they are deliberately NOT changed here: an audit entry that
  can be dropped is an audit trail with a hole in it, and that trade is the
  opposite of this one. **The difference is what the record is for** — evidence
  must be written or the act must fail; a diagnostic may be lost.

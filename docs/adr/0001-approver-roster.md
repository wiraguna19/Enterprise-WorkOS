# ADR 0001 — Approvals carry an approver roster

- **Status:** accepted
- **Date:** 2026-08-22 *(supersedes the 2026-08-20 draft, which named the table
  `approval_reviewers`; the shipped table is `approval_approvers`)*
- **Phase:** 4 (Workflow & Approvals)
- **Diverges from:** `docs/03-database-schema.md` §4, which shows `approvals`
  and `approval_decisions` and no third table.

## Context

`docs/02` §5 specifies three approval policies: `any_one`, `all_of`, and
`quorum`. The ERD in `docs/03` §4 models approvals with two tables — the
request, and the decisions recorded against it.

Implementing `all_of` against that schema exposes a gap. A decisions table
answers *who has decided*. Resolving `all_of` requires *who was asked*, and the
two sets differ for the entire life of a pending approval — which is exactly the
window every screen in the feature has to render.

Three ways to close the gap were considered.

**Derive the roster at decision time from a role or permission lookup.** Cheap,
and wrong in a way that only surfaces months later: adding somebody to a role
retroactively changes whether an approval that has already resolved *should*
have resolved. The audit trail stops being evidence of anything.

**Store the roster as a JSON array on `approvals`.** No migration, no join. But
the reviewer queue — "what is waiting on me", the most frequently issued query
in the feature — becomes a containment scan over a JSONB column on every page
render, and "two of three have responded" needs application code to compute
rather than a count.

**A roster table.** One more table, one more insert per submission.

## Decision

Add `approval_approvers (approval_id, membership_id, notified_at, …)`, written
at submit time.

`notified_at` records when the approver was actually told. It sits here rather
than being inferred from the notifications table because a notification can be
suppressed by preference, retried, or expired, and "was this person asked?" must
stay answerable regardless of what the delivery layer did.

## Consequences

- `all_of` and `quorum` resolve from data recorded at submission, so a later
  change to roles or team membership cannot rewrite the outcome of a decided
  approval.
- The UI can say "waiting on Maya" rather than "pending".
- `approval_decisions` is partitioned; the roster is not. The roster is bounded
  by approvers-per-approval and stays small, while decisions accumulate for the
  life of the tenant.
- One extra insert per approver at submit time. Approver counts are small and
  bounded by validation; this is not a hot path.
- `docs/03` §4 is now incomplete. It is left as written and this ADR is the
  amendment, per the Definition of Done in `docs/10`: "an ADR recorded for any
  decision that diverges from these documents".

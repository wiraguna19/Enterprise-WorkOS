# ADR 0002 — The recursion guard is threaded, and the chain is persisted

- **Status:** accepted
- **Date:** 2026-08-22 *(replaces a 2026-08-20 draft describing an
  `ApprovalStateReader` port; that port was removed when the rule engine landed
  and Workflow was allowed to depend on Approval directly — see "Consequences")*
- **Phase:** 4 (Workflow & Approvals)
- **Relates to:** `docs/02-domain-model.md` §7, `docs/04-module-structure.md` §3

## Context

`docs/02` §7 requires a recursion guard: "a rule-caused change carries a
causation chain, and the engine refuses to process a chain deeper than N
(default 5)". Two rules that trigger each other otherwise take the queue down,
and the document names this as the most common way workflow engines fail in
production.

"Carries a causation chain" admits two readings, and they are not equivalent.

## Decision

**Both, for different jobs.**

The guard itself is *threaded*: `EvaluateWorkflowRules` takes a `$depth`,
`RuleEngine::MAX_DEPTH` (5) refuses beyond it, and `ActionExecutor` passes the
value down through every action so anything an action triggers inherits the
depth rather than restarting at zero. This is what actually stops the loop, and
it stops it before any work is done.

The chain is separately *persisted*: every transition row carries `causation_id`
and `causation_depth`, bounded by a CHECK at 10. This does not enforce the
guard — the engine has already refused by then — it makes the chain readable
after the fact.

The distinction matters because the two have different failure modes. A threaded
counter is correct and cheap but evaporates the moment the request ends; a
persisted depth survives, but reading the guard back off the subject's last row
would be a lock and a race on the hot path. Using each for what it is good at
costs two columns.

## Consequences

- A chain that hits the ceiling is **recorded, not silently dropped**. It is a
  bug in somebody's rules, and they cannot fix what they cannot see.
- `causation_id` links a transition to the rule run that caused it, so "why did
  this move?" is answerable from the database months later, with no log
  retention required.
- The CHECK bound (10) is deliberately looser than `MAX_DEPTH` (5). The
  constraint is a backstop against a bug in the engine, not a second copy of the
  policy — pinning them together would mean every tuning change needs a
  migration.
- `workflow_rule_runs` records evaluations that matched nothing, too. A rule that
  silently never fires is indistinguishable from one that fires and does nothing
  unless the misses are recorded.
- **The `ApprovalStateReader` port was removed.** With Workflow needing to
  *create* approvals as a rule action, it depends on Approval outright, and a
  read-only port alongside a write dependency was ceremony. The cost is real and
  should be stated: `Workflow → Work` and `Work → Workflow` now both appear in
  `deptrac.yaml`, so the module graph is no longer a tree. Deptrac reports no
  violation because both edges are declared, but it is a cycle, and the comment
  above the `Workflow` layer in that file ("it knows nothing about the work that
  uses them") no longer describes the code.

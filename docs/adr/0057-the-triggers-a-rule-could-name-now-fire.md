# ADR 0057 — The triggers a rule could name now fire

- **Status:** accepted
- **Date:** 2026-09-30
- **Phase:** after Phase 7 (`docs/10`, the queue, item 6a)
- **Relates to:** `docs/02` §7 (Rules), ADR 0002, ADR 0014, ADR 0048

## Context

`WorkflowRuleModel::TRIGGERS` has named six triggers since Phase 4, and the
rule builder offered five of them. Only three were ever dispatched —
`work_item.created`, `.assigned` and `.status_changed`, by
`DispatchRuleEvaluation`. `schedule.due_soon`, `schedule.overdue` and
`approval.decided` were dispatched by nothing. A rule "when work goes overdue,
tell the project owner" could be written, saved, shown as active, and never ran
once; nothing said so. Found while choosing which events a webhook may
subscribe to (ADR 0048).

`docs/02` §7 lists deadline and decision triggers as part of the design, so the
answer was to make them fire, not to stop offering them.

## Decision

**`approval.decided` is dispatched by a listener; the two deadline triggers by
a scan every fifteen minutes that announces each deadline once, when it is
crossed.**

- **`approval.decided`**: `DispatchRuleEvaluation::onApprovalDecided`, for
  decisions whose subject is a work item — the only subject the engine's facts,
  conditions and actions describe. Registered after
  `TransitionOnApprovalDecision`, so a rule reading the item sees where the
  decision moved it. Two facts of the act join the vocabulary: `decision`
  (approved, changes_requested, rejected) and `resolution`.
- **`workflow:scan-deadlines`**, every fifteen minutes, on one server, without
  overlapping. `schedule.due_soon`: due within the next 24 hours.
  `schedule.overdue`: became overdue within the last 24 hours. Open work only;
  not archived, not deleted.
- **Crossings, not states.** `work_item_deadline_signals` records what was
  announced, keyed by item, signal AND due date: moving a deadline makes a new
  deadline, announced again when it comes. The insert is the claim, so two
  overlapping scans cannot announce the same one twice. Tenant-owned, with the
  `tenant_isolation` policy (ADR 0051).
- **The 24-hour lookback is what keeps the first run quiet.** Work already
  overdue for a week was overdue before any rule could ask about it; announcing
  every such item on deploy would send one notification per old late item in
  every organization at once. A day covers a scheduler that was down overnight.
- **Webhooks follow.** The three events are emitted now, so an endpoint may
  subscribe to them (`WebhookEndpoints::SUBSCRIBABLE`, and the CHECK on
  `webhook_endpoints.events`).

## Consequences

- A deadline rule fires at most fifteen minutes after the crossing. The
  builder already words them as "work is due soon" and "work is overdue", not
  as a moment.
- `schedule.due_soon`'s window is fixed at a day. `docs/02` names
  `schedule.due_in` with a parameter; a per-rule window needs the scan to ask
  every rule for its own, and one fixed window answers the common question.

## Not done

- **The `work.due_soon` notification type** is in the preferences screen and
  in `NotificationResource`, and nothing produces it — the same shape as this
  ADR's defect, one module over. A rule with a `notify` action now covers the
  case; the built-in notification is its own decision.
- `comment.created` and `field.changed`, also in `docs/02` §7, are not
  triggers at all yet — which is honest: nothing offers them.

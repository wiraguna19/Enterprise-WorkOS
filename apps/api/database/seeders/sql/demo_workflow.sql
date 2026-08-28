-- Demo seed, Phase 4 — the workflow graph, rules, and approvals in flight.
--
-- The transition graph below IS the default workflow docs/02 §7 describes,
-- expressed as rows. Nothing about it is hardcoded anywhere in the application;
-- deleting a row here removes a button from the UI.

BEGIN;

-- ── the legal moves ──────────────────────────────────────────────────────────
--
-- Read as a graph:
--
--   Backlog → Todo → In Progress → In Review → Approved → Completed
--                         ▲            │
--                         └── changes requested
--
--   Blocked and Cancelled are reachable from ANYWHERE (from_state_id NULL),
--   which is why those two rows replace what would otherwise be a dozen.
INSERT INTO workflow_transitions
 (id, organization_id, workflow_id, from_state_id, to_state_id, label, guard, requires_comment, position) VALUES

 -- Backlog → Todo
 ('01900020-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000001','01900002-0000-7000-8000-000000000002','Move to Todo','{}',false,0),

 -- Todo → In Progress. Only the person holding the work may start it: a
 -- manager marking someone else's work "in progress" makes the board lie.
 ('01900020-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000002','01900002-0000-7000-8000-000000000003','Start work',
  '{"actor_is":["assignee","creator"]}',false,1),

 -- In Progress → In Review. The submit step.
 ('01900020-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000003','01900002-0000-7000-8000-000000000004','Submit for review',
  '{"actor_is":["assignee","creator"]}',false,2),

 -- In Review → Approved. Guarded by permission AND by role: holding
 -- approval.decide is not enough if you are not one of this item's reviewers.
 ('01900020-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000004','01900002-0000-7000-8000-000000000005','Approve',
  '{"permission":"approval.decide","actor_is":["reviewer","approver"]}',false,3),

 -- In Review → In Progress: "request changes". requires_comment is the whole
 -- point — bouncing work back without a reason sends it round the loop again.
 ('01900020-0000-7000-8000-000000000005','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000004','01900002-0000-7000-8000-000000000003','Request changes',
  '{"permission":"approval.decide","actor_is":["reviewer","approver"]}',true,4),

 -- In Review → Cancelled: "reject". A rejection means the work should not
 -- continue, which is what distinguishes it from "request changes" — two
 -- decisions with two meanings rather than one meaning and a spare button
 -- (ADR 0005).
 --
 -- An edge of its own rather than the ANYWHERE → Cancelled row below, which is
 -- guarded by `work_item.delete` and therefore unusable by the very reviewer
 -- the product asked to decide. Cancelling FROM REVIEW is a reviewer's act;
 -- cancelling from anywhere else is still an owner's.
 ('01900020-0000-7000-8000-00000000000c','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000004','01900002-0000-7000-8000-000000000008','Reject',
  '{"permission":"approval.decide","actor_is":["reviewer","approver"]}',true,4),

 -- Approved → Completed
 ('01900020-0000-7000-8000-000000000006','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000005','01900002-0000-7000-8000-000000000006','Complete','{}',false,5),

 -- Reopen. Deliberately narrow: only someone with the override permission,
 -- because reopening completed work rewrites what a report already counted.
 ('01900020-0000-7000-8000-000000000007','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000006','01900002-0000-7000-8000-000000000003','Reopen',
  '{"permission":"work_item.transition_any"}',true,6),

 -- From ANYWHERE → Blocked / Cancelled. NULL from_state is what keeps this
 -- from being twelve near-identical rows.
 ('01900020-0000-7000-8000-000000000008','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  NULL,'01900002-0000-7000-8000-000000000007','Mark blocked','{}',true,7),
 ('01900020-0000-7000-8000-000000000009','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  NULL,'01900002-0000-7000-8000-000000000008','Cancel',
  '{"permission":"work_item.delete"}',true,8),

 -- Blocked → In Progress: the way out.
 ('01900020-0000-7000-8000-00000000000a','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000007','01900002-0000-7000-8000-000000000003','Unblock','{}',false,9),

 -- Backlog → In Progress, skipping Todo. Small workflows need the shortcut.
 ('01900020-0000-7000-8000-00000000000b','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000001','01900002-0000-7000-8000-000000000003','Start work',
  '{"actor_is":["assignee","creator"]}',false,10),

 -- The request workflow: a different shape entirely, proving the UI reads the
 -- graph rather than assuming one.
 ('01900020-0000-7000-8000-000000000011','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000002',
  '01900002-0000-7000-8000-000000000011','01900002-0000-7000-8000-000000000012','Triage','{}',false,0),
 ('01900020-0000-7000-8000-000000000012','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000002',
  '01900002-0000-7000-8000-000000000012','01900002-0000-7000-8000-000000000013','Resolve','{}',false,1),

 ('01900020-0000-7000-8000-000000000019','01900000-0000-7000-8000-0000000000b0','01900001-0000-7000-8000-000000000009',
  '01900002-0000-7000-8000-000000000019','01900002-0000-7000-8000-00000000001a','Complete','{}',false,0);

-- ── rules ────────────────────────────────────────────────────────────────────
-- Four, and each earns its place by automating something a person currently
-- does by hand and sometimes forgets.
INSERT INTO workflow_rules
 (id, organization_id, workflow_id, name, description, trigger, conditions, actions, is_active, run_order) VALUES

 -- 1. The flow docs/02 §6 describes, as configuration rather than a hardcoded
 --    branch: entering review opens an approval and tells the reviewer.
 --
 --    Matched on the state KEY, not the category. The Approved state also
 --    carries category `in_review` (it is still part of review), so a category
 --    match would re-fire this rule the moment an approval is granted and open
 --    a second approval for work that was just approved.
 ('01900021-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  'Open a review when work is submitted',
  'When work enters In Review, create an approval for its reviewers and notify them.',
  'work_item.status_changed',
  '{"all":[{"field":"to_state_key","op":"eq","value":"in_review"}]}',
  '[{"type":"create_approval","with":{"reviewers":"assigned_reviewers","policy":"any_one"}},
    {"type":"notify","with":{"to":["reviewer"],"notification_type":"approval.requested"}}]',
  true, 0),

 -- 2. Urgent work that nobody is holding is the gap a manager most needs to
 --    see, and the one most easily missed on a busy board.
 ('01900021-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac',NULL,
  'Flag unassigned urgent work',
  'When an urgent item is created without an assignee, tell the project owner.',
  'work_item.created',
  '{"all":[{"field":"priority","op":"in","value":["high","urgent"]},
           {"field":"assignee_membership_id","op":"is_null"}]}',
  '[{"type":"notify","with":{"to":["project_owner"],"notification_type":"work.needs_assignee",
     "message":"Urgent work was created without an assignee."}}]',
  true, 10),

 -- 3. Escalation NOTIFIES rather than reassigns. Silently moving someone''s work
 --    to their manager because it is late is a management decision, not an
 --    automation one — and a tool that makes it for you gets switched off.
 ('01900021-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac',NULL,
  'Escalate work overdue by three days',
  'Notify the assignee''s manager when high-priority work is three days late.',
  'schedule.overdue',
  '{"all":[{"field":"days_overdue","op":"gte","value":3},
           {"field":"priority","op":"in","value":["high","urgent"]}]}',
  '[{"type":"escalate","with":{"levels":1,"reason":"High-priority work is three days overdue."}}]',
  true, 20),

 -- 4. Route review to the manager when no reviewer was named, so a submission
 --    never lands nowhere.
 ('01900021-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001',
  'Route review to the manager when no reviewer is set',
  'If work reaches In Review with no reviewer assigned, assign the assignee''s manager.',
  'work_item.status_changed',
  '{"all":[{"field":"to_state_key","op":"eq","value":"in_review"}]}',
  '[{"type":"assign","with":{"role":"reviewer","to":"manager_of_assignee"}}]',
  true, 5),

 -- 5. Disabled, and deliberately present: an administrator needs to see what a
 --    failing rule looks like before one fails in anger.
 ('01900021-0000-7000-8000-000000000005','01900000-0000-7000-8000-0000000000ac',NULL,
  'Notify on completion (disabled)',
  'Example of a rule taken out of service after repeated failures.',
  'work_item.status_changed',
  '{"all":[{"field":"x","op":"changed_to","value":"done"}]}',
  '[{"type":"notify","with":{"to":["watchers"],"notification_type":"work.completed"}}]',
  false, 30);

UPDATE workflow_rules
   SET failure_count = 5,
       disabled_reason = 'Disabled after 5 consecutive failures: notification channel unavailable'
 WHERE id = '01900021-0000-7000-8000-000000000005';

-- ── an approval in flight ────────────────────────────────────────────────────
-- ENG-142 is in review with Ahmad as the reviewer. This is the row the review
-- screen renders, and the one the lifecycle test decides on.
INSERT INTO approvals
 (id, organization_id, subject_type, subject_id, requested_by_membership_id, status, policy,
  required_approvals, submission_note, submitted_at) VALUES
 ('01900022-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','work_item',
  '01900014-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000203','pending','any_one',1,
  E'Schema, repository layer, and the history endpoint are done.\n\nThe timeline component still needs the empty state, but the data is all there — happy to split that into a follow-up if you would rather ship this.',
  now() - interval '4 hours');

INSERT INTO approval_approvers (id, organization_id, approval_id, membership_id, notified_at) VALUES
 ('01900023-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac',
  '01900022-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000202', now() - interval '4 hours');

-- ── a completed round trip ───────────────────────────────────────────────────
-- The changes-requested → resubmit → approved narrative from docs/02 §6,
-- on a different item so the seed carries a FINISHED example as well as a
-- pending one. Both decisions are kept: a resolved approval that shows only
-- the final verdict hides the fact that the work was bounced back once.
INSERT INTO approvals
 (id, organization_id, subject_type, subject_id, requested_by_membership_id, status, policy,
  required_approvals, submission_note, submitted_at, resolved_at) VALUES
 ('01900022-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','work_item',
  '01900014-0000-7000-8000-000000000011','01900000-0000-7000-8000-000000000203','approved','any_one',1,
  'Migration and rollback both tested against a copy of production.',
  now() - interval '8 days', now() - interval '6 days');

INSERT INTO approval_approvers (id, organization_id, approval_id, membership_id, notified_at) VALUES
 ('01900023-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac',
  '01900022-0000-7000-8000-000000000002','01900000-0000-7000-8000-000000000202', now() - interval '8 days');

INSERT INTO approval_decisions
 (id, organization_id, approval_id, reviewer_membership_id, decision, comment, decided_at) VALUES
 ('01900024-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac',
  '01900022-0000-7000-8000-000000000002','01900000-0000-7000-8000-000000000202','changes_requested',
  'The rollback path drops the index without recreating it. Please add that before this goes out.',
  now() - interval '7 days'),
 ('01900024-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac',
  '01900022-0000-7000-8000-000000000002','01900000-0000-7000-8000-000000000202','approved',
  'Rollback verified. Good to go.',
  now() - interval '6 days');

-- ── transition history ───────────────────────────────────────────────────────
-- ENG-142''s journey, including the reassignment and the review. Cycle time is
-- computed from these rows, so the seed has to contain a plausible one.
INSERT INTO work_item_transitions
 (id, organization_id, work_item_id, from_state_id, to_state_id, from_category, to_category,
  actor_membership_id, cause, causation_id, causation_depth, occurred_at) VALUES
 ('01900025-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900014-0000-7000-8000-000000000001',
  NULL,'01900002-0000-7000-8000-000000000001',NULL,'backlog',
  '01900000-0000-7000-8000-000000000202','user','01900025-0000-7000-8000-000000000001',0, now() - interval '12 days'),
 ('01900025-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','01900014-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000001','01900002-0000-7000-8000-000000000002','backlog','todo',
  '01900000-0000-7000-8000-000000000202','user','01900025-0000-7000-8000-000000000002',0, now() - interval '12 days'),
 ('01900025-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','01900014-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000002','01900002-0000-7000-8000-000000000003','todo','in_progress',
  '01900000-0000-7000-8000-000000000204','user','01900025-0000-7000-8000-000000000003',0, now() - interval '11 days'),
 -- Sarah picks it up after the handover and submits.
 ('01900025-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','01900014-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000003','01900002-0000-7000-8000-000000000004','in_progress','in_review',
  '01900000-0000-7000-8000-000000000203','user','01900025-0000-7000-8000-000000000004',0, now() - interval '4 hours');

-- ── rule runs ────────────────────────────────────────────────────────────────
-- Including a SKIPPED one, because "why didn''t my rule fire?" is the first
-- question anyone asks and the answer is usually "the condition did not match".
INSERT INTO workflow_rule_runs
 (id, organization_id, rule_id, subject_type, subject_id, causation_id, causation_depth,
  outcome, matched, actions_run, duration_ms, occurred_at) VALUES
 ('01900026-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900021-0000-7000-8000-000000000001',
  'work_item','01900014-0000-7000-8000-000000000001','01900025-0000-7000-8000-000000000004',0,
  'applied',true,'[{"type":"create_approval","approval_id":"01900022-0000-7000-8000-000000000001","reviewers":1},
                   {"type":"notify","recipients":1,"delivered":1}]', 43, now() - interval '4 hours'),
 ('01900026-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','01900021-0000-7000-8000-000000000004',
  'work_item','01900014-0000-7000-8000-000000000001','01900025-0000-7000-8000-000000000004',0,
  'applied',true,'[{"type":"assign","skipped":"already assigned"}]', 12, now() - interval '4 hours'),
 ('01900026-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','01900021-0000-7000-8000-000000000002',
  'work_item','01900014-0000-7000-8000-000000000003','01900025-0000-7000-8000-000000000003',0,
  'skipped',false,'[]', 3, now() - interval '5 days');

-- ── notifications ────────────────────────────────────────────────────────────
-- The inbox has to have something in it, and the payload snapshot is what
-- makes each row render without a join (docs/03 §5).
INSERT INTO notifications
 (id, organization_id, membership_id, type, subject_type, subject_id, actor_membership_id,
  payload, dedupe_key, read_at, created_at) VALUES
 -- Ahmad is asked to review ENG-142.
 ('01900027-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000202',
  'approval.requested','work_item','01900014-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000203',
  '{"reference":"ENG-142","title":"Implement assignment history so reassignment is never lost","actor_name":"Sarah Chen","approval_id":"01900022-0000-7000-8000-000000000001"}',
  'approval.requested:01900014-0000-7000-8000-000000000001:01900022-0000-7000-8000-000000000001', NULL, now() - interval '4 hours'),

 -- Sarah was handed ENG-142.
 ('01900027-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000203',
  'work.assigned','work_item','01900014-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000202',
  '{"reference":"ENG-142","title":"Implement assignment history so reassignment is never lost","actor_name":"Ahmad Rizal","handover":true,"reason":"Reassigned to Sarah — David pulled onto the checkout incident"}',
  'work.assigned:01900014-0000-7000-8000-000000000001:handover', now() - interval '8 days', now() - interval '9 days'),

 -- David lost it — the other half of the same handover, worded differently
 -- because it means something different to him.
 ('01900027-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000204',
  'work.reassigned_away','work_item','01900014-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000202',
  '{"reference":"ENG-142","title":"Implement assignment history so reassignment is never lost","actor_name":"Ahmad Rizal","reason":"Reassigned to Sarah — David pulled onto the checkout incident"}',
  'work.reassigned_away:01900014-0000-7000-8000-000000000001:away', now() - interval '8 days', now() - interval '9 days'),

 -- Sarah's earlier submission was bounced back and later approved.
 ('01900027-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000203',
  'approval.changes_requested','work_item','01900014-0000-7000-8000-000000000011','01900000-0000-7000-8000-000000000202',
  '{"reference":"ENG-145","title":"Schema and migration","actor_name":"Ahmad Rizal","comment":"The rollback path drops the index without recreating it."}',
  'approval.changes_requested:01900014-0000-7000-8000-000000000011:changes', now() - interval '6 days', now() - interval '7 days'),
 ('01900027-0000-7000-8000-000000000005','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000203',
  'approval.approved','work_item','01900014-0000-7000-8000-000000000011','01900000-0000-7000-8000-000000000202',
  '{"reference":"ENG-145","title":"Schema and migration","actor_name":"Ahmad Rizal","comment":"Rollback verified. Good to go."}',
  'approval.approved:01900014-0000-7000-8000-000000000011:approved', NULL, now() - interval '6 days'),

 -- A mention, so the inbox shows more than one kind of thing.
 ('01900027-0000-7000-8000-000000000006','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000202',
  'comment.mentioned','work_item','01900014-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000203',
  '{"reference":"ENG-142","title":"Implement assignment history so reassignment is never lost","actor_name":"Sarah Chen"}',
  'comment.mentioned:01900014-0000-7000-8000-000000000001:01900017-0000-7000-8000-000000000001', NULL, now() - interval '2 days'),

 -- Escalation on the overdue incident.
 ('01900027-0000-7000-8000-000000000007','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000202',
  'work.escalated','work_item','01900012-0000-7000-8000-000000000003',NULL,
  '{"reference":"OPS-1","title":"Checkout latency spike between 14:00 and 14:20","reason":"High-priority work is three days overdue."}',
  'work.escalated:01900012-0000-7000-8000-000000000003:overdue', NULL, now() - interval '1 day');

-- ── notification preferences ─────────────────────────────────────────────────
-- David has muted the low-signal type and kept the rest. Proves the preference
-- path is real rather than a form that writes nowhere.
INSERT INTO notification_preferences (id, organization_id, membership_id, type, in_app, email, digest) VALUES
 ('01900028-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000204',
  'work.completed', false, false, 'off'),
 ('01900028-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000202',
  'approval.requested', true, true, 'off'),
 ('01900028-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000205',
  'work.assigned', true, false, 'daily');

-- ── role grants for the new permissions ──────────────────────────────────────
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.key = 'org_admin'
  AND p.key LIKE ANY (ARRAY['approval.%','workflow.%','notification.%'])
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.key = 'manager' AND p.key IN (
    'approval.request','approval.decide','approval.withdraw',
    'workflow.view','notification.manage_own'
);

-- An Employee may REQUEST review and manage their own notifications, but not
-- decide. That denial is the one the lifecycle test asserts.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.key = 'employee' AND p.key IN (
    'approval.request','approval.withdraw','workflow.view','notification.manage_own'
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.key = 'viewer' AND p.key IN ('workflow.view');

COMMIT;

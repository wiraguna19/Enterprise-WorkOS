-- Demo seed, Phase 3 — projects and work.
--
-- Runs after demo_organization.sql. Deliberately NOT tidy (docs/10, "Seed
-- data"): it contains overdue work, an over-committed person, unestimated
-- items, unassigned work, a project with nothing in it, a 90-character title,
-- a full reassignment narrative, and a changes-requested round trip — because
-- those are the states that break interfaces, and a seed of three neat rows
-- proves nothing.

BEGIN;

-- ── the default workflow ─────────────────────────────────────────────────────
-- States are DATA, not an enum. Each maps to one of seven fixed categories, and
-- the UI keys off the category — which is how a customer can rename "In Review"
-- to "QA Gate" without breaking a dashboard (docs/02 §7).
INSERT INTO workflows (id, organization_id, name, applies_to_type, version, is_default, is_active) VALUES
 ('01900001-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','Default Task Workflow','task',1,true,true),
 ('01900001-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','Request Workflow','request',1,true,true),
 ('01900001-0000-7000-8000-000000000009','01900000-0000-7000-8000-0000000000b0','Default Task Workflow','task',1,true,true);

INSERT INTO workflow_states
 (id, organization_id, workflow_id, key, label, category, color, position, is_initial, is_terminal, requires_approval) VALUES
 ('01900002-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001','backlog',    'Backlog',     'backlog',    'neutral',0,true, false,false),
 ('01900002-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001','todo',       'Todo',        'todo',       'info',   1,false,false,false),
 ('01900002-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001','in_progress','In Progress', 'in_progress','active', 2,false,false,false),
 ('01900002-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001','in_review',  'In Review',   'in_review',  'review', 3,false,false,true),
 ('01900002-0000-7000-8000-000000000005','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001','approved',   'Approved',    'in_review',  'success',4,false,false,false),
 ('01900002-0000-7000-8000-000000000006','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001','completed',  'Completed',   'done',       'success',5,false,true, false),
 ('01900002-0000-7000-8000-000000000007','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001','blocked',    'Blocked',     'blocked',    'danger', 6,false,false,false),
 ('01900002-0000-7000-8000-000000000008','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000001','cancelled',  'Cancelled',   'cancelled',  'neutral',7,false,true, false),
 -- The request workflow is intentionally shorter: proof that a second workflow
 -- with a different shape works without any UI change.
 ('01900002-0000-7000-8000-000000000011','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000002','submitted',  'Submitted',   'todo',       'info',   0,true, false,false),
 ('01900002-0000-7000-8000-000000000012','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000002','triaged',    'Triaged',     'in_progress','active', 1,false,false,false),
 ('01900002-0000-7000-8000-000000000013','01900000-0000-7000-8000-0000000000ac','01900001-0000-7000-8000-000000000002','resolved',   'Resolved',    'done',       'success',2,false,true, false),
 ('01900002-0000-7000-8000-000000000019','01900000-0000-7000-8000-0000000000b0','01900001-0000-7000-8000-000000000009','todo',       'Todo',        'todo',       'info',   0,true, false,false),
 ('01900002-0000-7000-8000-00000000001a','01900000-0000-7000-8000-0000000000b0','01900001-0000-7000-8000-000000000009','done',       'Done',        'done',       'success',1,false,true, false);

-- ── role grants for the new permissions ──────────────────────────────────────
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.key = 'org_admin'
  AND p.key LIKE ANY (ARRAY['project.%','work_item.%','milestone.%','comment.%','file.%','tag.%','saved_view.%'])
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.key = 'manager' AND p.key IN (
    'project.view','project.view_all','project.create','project.update','project.archive','project.manage_members',
    'milestone.manage',
    'work_item.view','work_item.create','work_item.update','work_item.delete',
    'work_item.assign','work_item.transition','work_item.submit','work_item.log_time',
    'comment.create','comment.update_own','file.upload','tag.manage','saved_view.share'
);

-- Note what an Employee CANNOT do: assign work to others, view projects they
-- are not a member of, or delete anything. Those are the denials the tests
-- assert (docs/11 §3).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.key = 'employee' AND p.key IN (
    'project.view','work_item.view','work_item.create','work_item.update',
    'work_item.transition','work_item.submit','work_item.log_time',
    'comment.create','comment.update_own','file.upload'
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.key = 'viewer' AND p.key IN ('project.view','work_item.view');

-- ── projects ─────────────────────────────────────────────────────────────────
INSERT INTO projects
 (id, organization_id, key, name, description, owner_membership_id, department_id, workflow_id,
  status, priority, visibility, start_date, end_date, budget_amount, budget_currency, archived_at) VALUES
 ('01900003-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','ENG','Platform Rebuild',
  'Replace the legacy monolith with the modular platform. Phase 1 covers identity, organization, and the work core.',
  '01900000-0000-7000-8000-000000000202','01900000-0000-7000-8000-000000000601','01900001-0000-7000-8000-000000000001',
  'active','high','internal', current_date - 120, current_date + 60, 450000.00,'USD',NULL),

 ('01900003-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','WEB','Website Refresh',
  'New marketing site, design system alignment, and CMS migration.',
  '01900000-0000-7000-8000-000000000207','01900000-0000-7000-8000-000000000602','01900001-0000-7000-8000-000000000001',
  'active','medium','internal', current_date - 75, current_date + 14, 80000.00,'USD',NULL),

 -- Not started: every list and board must have an honest empty-ish state.
 ('01900003-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','MKT','Q3 Campaign',
  'Product launch campaign: content, paid, and lifecycle.',
  '01900000-0000-7000-8000-000000000207','01900000-0000-7000-8000-000000000602','01900001-0000-7000-8000-000000000001',
  'planning','medium','internal', current_date + 7, current_date + 97, NULL,NULL,NULL),

 -- Archived: proves archived work is excluded by default but still reachable.
 ('01900003-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','LEG','Legacy Migration',
  'Decommission the old scheduler. Completed last quarter.',
  '01900000-0000-7000-8000-000000000202','01900000-0000-7000-8000-000000000601','01900001-0000-7000-8000-000000000001',
  'completed','low','internal', current_date - 300, current_date - 90, NULL,NULL, now() - interval '80 days'),

 -- Private: Sarah is NOT a member, so it must be invisible to her.
 ('01900003-0000-7000-8000-000000000005','01900000-0000-7000-8000-0000000000ac','FIN','Budget Planning FY27',
  'Confidential financial planning.',
  '01900000-0000-7000-8000-000000000201','01900000-0000-7000-8000-000000000603','01900001-0000-7000-8000-000000000001',
  'active','high','private', current_date - 10, current_date + 80, NULL,NULL,NULL),

 ('01900003-0000-7000-8000-000000000009','01900000-0000-7000-8000-0000000000b0','GBX','Globex Internal',
  'Should never be visible from Acme.',
  '01900000-0000-7000-8000-000000000301','01900000-0000-7000-8000-000000000701','01900001-0000-7000-8000-000000000009',
  'active','medium','internal', current_date - 30, NULL, NULL,NULL,NULL);

INSERT INTO project_members (id, organization_id, project_id, membership_id, team_id, role, added_at, removed_at) VALUES
 ('01900004-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000202',NULL,'owner',  now()-interval '120 days',NULL),
 ('01900004-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000203',NULL,'member', now()-interval '118 days',NULL),
 ('01900004-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000204',NULL,'member', now()-interval '118 days',NULL),
 ('01900004-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000205',NULL,'member', now()-interval '100 days',NULL),
 ('01900004-0000-7000-8000-000000000005','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000206',NULL,'member', now()-interval '60 days', NULL),
 ('01900004-0000-7000-8000-000000000006','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000002','01900000-0000-7000-8000-000000000207',NULL,'owner',  now()-interval '75 days', NULL),
 ('01900004-0000-7000-8000-000000000007','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000002','01900000-0000-7000-8000-000000000203',NULL,'member', now()-interval '70 days', NULL),
 ('01900004-0000-7000-8000-000000000008','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000002','01900000-0000-7000-8000-000000000206',NULL,'member', now()-interval '70 days', NULL),
 ('01900004-0000-7000-8000-000000000009','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000003','01900000-0000-7000-8000-000000000207',NULL,'owner',  now()-interval '20 days', NULL),
 -- Team-based membership: the whole Frontend team, not a list of people.
 ('01900004-0000-7000-8000-00000000000a','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000002',NULL,'01900000-0000-7000-8000-000000000801','member', now()-interval '70 days', NULL),
 ('01900004-0000-7000-8000-00000000000b','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000005','01900000-0000-7000-8000-000000000201',NULL,'owner',  now()-interval '10 days', NULL),
 -- David left the Website project a month ago; the row is closed, not deleted.
 ('01900004-0000-7000-8000-00000000000c','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000002','01900000-0000-7000-8000-000000000204',NULL,'member', now()-interval '70 days', now()-interval '30 days'),
 ('01900004-0000-7000-8000-000000000019','01900000-0000-7000-8000-0000000000b0','01900003-0000-7000-8000-000000000009','01900000-0000-7000-8000-000000000301',NULL,'owner',  now()-interval '30 days', NULL);

INSERT INTO milestones (id, organization_id, project_id, name, description, due_date, status, position, completed_at) VALUES
 ('01900005-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000001','Foundation','Identity, organization, tenancy', current_date - 30,'completed',0, now()-interval '28 days'),
 ('01900005-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000001','Work Core','Projects, work items, assignment',  current_date + 10,'open',     1, NULL),
 ('01900005-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000001','Workflow','Approvals and automation',          current_date + 45,'open',     2, NULL),
 -- At risk, and overdue: the milestone health indicator needs both to exist.
 ('01900005-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000002','Design System Alignment','', current_date - 5,'at_risk', 0, NULL),
 ('01900005-0000-7000-8000-000000000005','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000002','CMS Migration','',           current_date + 12,'open',    1, NULL),
 ('01900005-0000-7000-8000-000000000006','01900000-0000-7000-8000-0000000000ac','01900003-0000-7000-8000-000000000003','Campaign Brief','',          current_date + 2, 'open',    0, NULL);

-- ── tags ─────────────────────────────────────────────────────────────────────
INSERT INTO tags (id, organization_id, name, color) VALUES
 ('01900006-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','urgent','danger'),
 ('01900006-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','tech-debt','neutral'),
 ('01900006-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','security','review'),
 ('01900006-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','customer-request','info'),
 ('01900006-0000-7000-8000-000000000005','01900000-0000-7000-8000-0000000000ac','needs-design','active');

COMMIT;

-- Demo seed — Phase 2.
--
-- Written as SQL rather than as Eloquent factories so it can be executed and
-- verified without booting the framework, and so the data it produces is
-- identical in every environment and every test run.
--
-- Deliberately NOT tidy. It contains a second tenant, a part-time employee, a
-- contractor with read-only access, a suspended account, and an empty
-- department — because tidy seed data hides exactly the cases that break
-- interfaces (docs/10, "Seed data").
--
-- Every account uses the password: password
-- (bcrypt cost 10, fixed hash — development only; production accounts are
--  created through the invitation flow and never seeded.)

BEGIN;

-- ── organizations ────────────────────────────────────────────────────────────
-- Globex exists solely so that a cross-tenant leak is visible during ordinary
-- manual testing, not only in the isolation suite (docs/12 §4).
INSERT INTO organizations (id, name, slug, plan, status, settings) VALUES
 ('01900000-0000-7000-8000-0000000000ac', 'Acme Corporation', 'acme',   'internal', 'active',
  '{"approvals":{"allow_self_review":false},"work":{"default_estimate_hours":4}}'),
 ('01900000-0000-7000-8000-0000000000b0', 'Globex Inc',       'globex', 'internal', 'active',
  '{}');

-- ── users (global identities) ────────────────────────────────────────────────
INSERT INTO users (id, email, name, password_hash, timezone, locale, email_verified_at) VALUES
 ('01900000-0000-7000-8000-000000000001', 'rina@acme.test',  'Rina Wijaya',  '$2y$10$y64GTc6sRPh9wzUsZjvF2uW.00A57Pdd8OJw6Vn8maEZBAfsaKMke', 'Asia/Jakarta', 'id', now()),
 ('01900000-0000-7000-8000-000000000002', 'ahmad@acme.test', 'Ahmad Rizal',  '$2y$10$y64GTc6sRPh9wzUsZjvF2uW.00A57Pdd8OJw6Vn8maEZBAfsaKMke', 'Asia/Jakarta', 'id', now()),
 ('01900000-0000-7000-8000-000000000003', 'sarah@acme.test', 'Sarah Chen',   '$2y$10$y64GTc6sRPh9wzUsZjvF2uW.00A57Pdd8OJw6Vn8maEZBAfsaKMke', 'Asia/Singapore', 'en', now()),
 ('01900000-0000-7000-8000-000000000004', 'david@acme.test', 'David Park',   '$2y$10$y64GTc6sRPh9wzUsZjvF2uW.00A57Pdd8OJw6Vn8maEZBAfsaKMke', 'Asia/Seoul', 'en', now()),
 ('01900000-0000-7000-8000-000000000005', 'maya@acme.test',  'Maya Putri',   '$2y$10$y64GTc6sRPh9wzUsZjvF2uW.00A57Pdd8OJw6Vn8maEZBAfsaKMke', 'Asia/Jakarta', 'id', now()),
 ('01900000-0000-7000-8000-000000000006', 'budi@acme.test',  'Budi Santoso', '$2y$10$y64GTc6sRPh9wzUsZjvF2uW.00A57Pdd8OJw6Vn8maEZBAfsaKMke', 'Asia/Jakarta', 'id', now()),
 ('01900000-0000-7000-8000-000000000007', 'lisa@acme.test',  'Lisa Tan',     '$2y$10$y64GTc6sRPh9wzUsZjvF2uW.00A57Pdd8OJw6Vn8maEZBAfsaKMke', 'Asia/Singapore', 'en', now()),
 ('01900000-0000-7000-8000-000000000008', 'tono@acme.test',  'Tono Hartono', '$2y$10$y64GTc6sRPh9wzUsZjvF2uW.00A57Pdd8OJw6Vn8maEZBAfsaKMke', 'Asia/Jakarta', 'id', now()),
 -- suspended: proves the UI and the auth path handle a revoked person
 ('01900000-0000-7000-8000-000000000009', 'former@acme.test','Eko Prasetyo', '$2y$10$y64GTc6sRPh9wzUsZjvF2uW.00A57Pdd8OJw6Vn8maEZBAfsaKMke', 'Asia/Jakarta', 'id', now()),
 -- the other tenant
 ('01900000-0000-7000-8000-000000000101', 'gil@globex.test', 'Gil Barnes',   '$2y$10$y64GTc6sRPh9wzUsZjvF2uW.00A57Pdd8OJw6Vn8maEZBAfsaKMke', 'UTC', 'en', now());

-- ── memberships ──────────────────────────────────────────────────────────────
INSERT INTO memberships (id, organization_id, user_id, status, invited_at, joined_at, revoked_at) VALUES
 ('01900000-0000-7000-8000-000000000201','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000001','active', now()-interval '2 years', now()-interval '2 years', NULL),
 ('01900000-0000-7000-8000-000000000202','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000002','active', now()-interval '18 months', now()-interval '18 months', NULL),
 ('01900000-0000-7000-8000-000000000203','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000003','active', now()-interval '14 months', now()-interval '14 months', NULL),
 ('01900000-0000-7000-8000-000000000204','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000004','active', now()-interval '11 months', now()-interval '11 months', NULL),
 ('01900000-0000-7000-8000-000000000205','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000005','active', now()-interval '8 months',  now()-interval '8 months',  NULL),
 ('01900000-0000-7000-8000-000000000206','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000006','active', now()-interval '6 months',  now()-interval '6 months',  NULL),
 ('01900000-0000-7000-8000-000000000207','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000007','active', now()-interval '5 months',  now()-interval '5 months',  NULL),
 ('01900000-0000-7000-8000-000000000208','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000008','active', now()-interval '2 months',  now()-interval '2 months',  NULL),
 ('01900000-0000-7000-8000-000000000209','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000009','revoked',now()-interval '3 years',  now()-interval '3 years',  now()-interval '1 month'),
 ('01900000-0000-7000-8000-000000000301','01900000-0000-7000-8000-0000000000b0','01900000-0000-7000-8000-000000000101','active', now()-interval '1 year',   now()-interval '1 year',   NULL);

-- ── roles ────────────────────────────────────────────────────────────────────
-- `level` is for display ordering and for "can this role manage that role",
-- never for permission decisions — those read the permission set (docs/06 §2).
INSERT INTO roles (id, organization_id, key, name, description, is_system, level) VALUES
 ('01900000-0000-7000-8000-000000000401','01900000-0000-7000-8000-0000000000ac','org_admin','Organization Admin','Full control within the organization',true,90),
 ('01900000-0000-7000-8000-000000000402','01900000-0000-7000-8000-0000000000ac','manager',  'Manager',           'Runs projects and reviews work',       true,60),
 ('01900000-0000-7000-8000-000000000403','01900000-0000-7000-8000-0000000000ac','employee', 'Employee',          'Works on assigned work',               true,30),
 ('01900000-0000-7000-8000-000000000404','01900000-0000-7000-8000-0000000000ac','viewer',   'Viewer',            'Read-only access to granted areas',    true,10),
 ('01900000-0000-7000-8000-000000000501','01900000-0000-7000-8000-0000000000b0','org_admin','Organization Admin','Full control within the organization', true,90),
 ('01900000-0000-7000-8000-000000000502','01900000-0000-7000-8000-0000000000b0','employee', 'Employee',          'Works on assigned work',               true,30);

-- ── role → permission grants ─────────────────────────────────────────────────
-- Org Admin: everything in the catalogue.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.key = 'org_admin';

-- Manager: structural read, people read + workload, no role or audit access.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.key = 'manager' AND p.key IN (
    'organization.view',
    'department.view','team.view','team.create','team.update','team.manage_members',
    'person.view','person.view_workload','person.invite',
    'activity.view'
);

-- Employee: sees the org and its people, changes nothing structural.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.key = 'employee' AND p.key IN (
    'organization.view','department.view','team.view','person.view','activity.view'
);

-- Viewer: the narrowest possible set.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.key = 'viewer' AND p.key IN ('organization.view','team.view','person.view');

-- ── role assignments ─────────────────────────────────────────────────────────
INSERT INTO membership_roles (organization_id, membership_id, role_id) VALUES
 ('01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000201','01900000-0000-7000-8000-000000000401'),
 ('01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000202','01900000-0000-7000-8000-000000000402'),
 ('01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000203','01900000-0000-7000-8000-000000000403'),
 ('01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000204','01900000-0000-7000-8000-000000000403'),
 ('01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000205','01900000-0000-7000-8000-000000000403'),
 ('01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000206','01900000-0000-7000-8000-000000000403'),
 ('01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000207','01900000-0000-7000-8000-000000000403'),
 ('01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000208','01900000-0000-7000-8000-000000000404'),
 ('01900000-0000-7000-8000-0000000000b0','01900000-0000-7000-8000-000000000301','01900000-0000-7000-8000-000000000501');

-- ── departments (materialized path) ──────────────────────────────────────────
-- Operations is intentionally empty — an empty state that ships with the seed
-- is an empty state somebody actually designs.
INSERT INTO departments (id, organization_id, parent_id, name, code, head_membership_id, path, depth) VALUES
 ('01900000-0000-7000-8000-000000000601','01900000-0000-7000-8000-0000000000ac',NULL,'Engineering','ENG','01900000-0000-7000-8000-000000000202','/01900000-0000-7000-8000-000000000601/',0),
 ('01900000-0000-7000-8000-000000000602','01900000-0000-7000-8000-0000000000ac',NULL,'Marketing',  'MKT',NULL,'/01900000-0000-7000-8000-000000000602/',0),
 ('01900000-0000-7000-8000-000000000603','01900000-0000-7000-8000-0000000000ac',NULL,'Finance',    'FIN',NULL,'/01900000-0000-7000-8000-000000000603/',0),
 ('01900000-0000-7000-8000-000000000604','01900000-0000-7000-8000-0000000000ac',NULL,'Operations', 'OPS',NULL,'/01900000-0000-7000-8000-000000000604/',0),
 -- a nested department, so path queries are exercised by the seed itself
 ('01900000-0000-7000-8000-000000000605','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000601','Quality Assurance','ENG-QA','01900000-0000-7000-8000-000000000205','/01900000-0000-7000-8000-000000000601/01900000-0000-7000-8000-000000000605/',1),
 ('01900000-0000-7000-8000-000000000701','01900000-0000-7000-8000-0000000000b0',NULL,'Operations','OPS',NULL,'/01900000-0000-7000-8000-000000000701/',0);

-- ── teams ────────────────────────────────────────────────────────────────────
INSERT INTO teams (id, organization_id, department_id, name, key, description, lead_membership_id) VALUES
 ('01900000-0000-7000-8000-000000000801','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000601','Frontend','FE','Web client and design system','01900000-0000-7000-8000-000000000203'),
 ('01900000-0000-7000-8000-000000000802','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000601','Backend', 'BE','API, data, and platform',      '01900000-0000-7000-8000-000000000204'),
 ('01900000-0000-7000-8000-000000000803','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000605','QA',      'QA','Quality and release readiness','01900000-0000-7000-8000-000000000205'),
 ('01900000-0000-7000-8000-000000000804','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000602','Marketing','MKTG','Campaigns and content',      '01900000-0000-7000-8000-000000000207'),
 ('01900000-0000-7000-8000-000000000901','01900000-0000-7000-8000-0000000000b0','01900000-0000-7000-8000-000000000701','Ops','OPS','Globex operations',NULL);

-- ── team membership (including one historical row) ───────────────────────────
INSERT INTO team_members (id, organization_id, team_id, membership_id, role, joined_at, left_at) VALUES
 ('01900000-0000-7000-8000-00000000a001','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000801','01900000-0000-7000-8000-000000000203','lead',  now()-interval '14 months',NULL),
 ('01900000-0000-7000-8000-00000000a002','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000801','01900000-0000-7000-8000-000000000206','member',now()-interval '6 months', NULL),
 ('01900000-0000-7000-8000-00000000a003','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000802','01900000-0000-7000-8000-000000000204','lead',  now()-interval '11 months',NULL),
 ('01900000-0000-7000-8000-00000000a004','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000803','01900000-0000-7000-8000-000000000205','lead',  now()-interval '8 months', NULL),
 ('01900000-0000-7000-8000-00000000a005','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000804','01900000-0000-7000-8000-000000000207','lead',  now()-interval '5 months', NULL),
 ('01900000-0000-7000-8000-00000000a006','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000802','01900000-0000-7000-8000-000000000202','member',now()-interval '18 months',NULL),
 -- David moved from Frontend to Backend a year ago; the history stays.
 ('01900000-0000-7000-8000-00000000a007','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000801','01900000-0000-7000-8000-000000000204','member',now()-interval '11 months',now()-interval '10 months'),
 ('01900000-0000-7000-8000-00000000b001','01900000-0000-7000-8000-0000000000b0','01900000-0000-7000-8000-000000000901','01900000-0000-7000-8000-000000000301','lead',  now()-interval '1 year',  NULL);

-- ── employee profiles ────────────────────────────────────────────────────────
-- Capacity varies on purpose: a workload bar built on a uniform 40 hours has
-- never been tested against reality (docs/02 §11).
INSERT INTO employee_profiles
 (id, organization_id, membership_id, employee_number, job_title, department_id, manager_profile_id, employment_type, weekly_capacity_hours, hired_at, work_location) VALUES
 ('01900000-0000-7000-8000-00000000c001','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000201','ACM-0001','Head of Operations',   '01900000-0000-7000-8000-000000000604',NULL,'full_time',40.00, current_date - 730,'Jakarta HQ'),
 ('01900000-0000-7000-8000-00000000c002','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000202','ACM-0002','Engineering Manager',  '01900000-0000-7000-8000-000000000601','01900000-0000-7000-8000-00000000c001','full_time',40.00, current_date - 540,'Jakarta HQ'),
 ('01900000-0000-7000-8000-00000000c003','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000203','ACM-0003','Frontend Developer',   '01900000-0000-7000-8000-000000000601','01900000-0000-7000-8000-00000000c002','full_time',40.00, current_date - 420,'Remote — Singapore'),
 ('01900000-0000-7000-8000-00000000c004','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000204','ACM-0004','Backend Developer',    '01900000-0000-7000-8000-000000000601','01900000-0000-7000-8000-00000000c002','full_time',40.00, current_date - 330,'Remote — Seoul'),
 ('01900000-0000-7000-8000-00000000c005','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000205','ACM-0005','QA Engineer',          '01900000-0000-7000-8000-000000000605','01900000-0000-7000-8000-00000000c002','full_time',40.00, current_date - 240,'Jakarta HQ'),
 -- part-time: 24 h/week, so utilization maths cannot assume 40
 ('01900000-0000-7000-8000-00000000c006','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000206','ACM-0006','Product Designer',     '01900000-0000-7000-8000-000000000601','01900000-0000-7000-8000-00000000c002','part_time',24.00, current_date - 180,'Bandung'),
 ('01900000-0000-7000-8000-00000000c007','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000207','ACM-0007','Marketing Specialist', '01900000-0000-7000-8000-000000000602','01900000-0000-7000-8000-00000000c001','full_time',40.00, current_date - 150,'Jakarta HQ'),
 -- contractor: viewer role, low capacity
 ('01900000-0000-7000-8000-00000000c008','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000208',NULL,      'External Consultant', NULL,                                  '01900000-0000-7000-8000-00000000c001','contract', 16.00, current_date - 60, 'Remote'),
 ('01900000-0000-7000-8000-00000000d001','01900000-0000-7000-8000-0000000000b0','01900000-0000-7000-8000-000000000301','GBX-0001','Operations Lead',      '01900000-0000-7000-8000-000000000701',NULL,'full_time',40.00, current_date - 365,'Springfield');

-- ── organization settings ────────────────────────────────────────────────────
INSERT INTO settings (id, organization_id, scope_type, scope_id, key, value) VALUES
 ('01900000-0000-7000-8000-00000000e001','01900000-0000-7000-8000-0000000000ac','organization',NULL,'work.default_estimate_hours','4'),
 ('01900000-0000-7000-8000-00000000e002','01900000-0000-7000-8000-0000000000ac','organization',NULL,'approvals.allow_self_review','false'),
 ('01900000-0000-7000-8000-00000000e003','01900000-0000-7000-8000-0000000000ac','organization',NULL,'security.session_idle_hours','8'),
 ('01900000-0000-7000-8000-00000000e004','01900000-0000-7000-8000-0000000000ac','department','01900000-0000-7000-8000-000000000601','work.default_estimate_hours','8');

COMMIT;

-- ── KPIs (ADR 0062) ─────────────────────────────────────────────────────────
-- Something to look at on /kpis, on Home and on two people's pages, without
-- typing a single value by hand.
--
-- Every period is computed from today, so the seed never goes stale: the last
-- value is always "this month so far" and the history always ends now
-- (SeedIsDateIndependentTest is the reason for the rule). Ids are fixed and
-- every insert is ON CONFLICT DO NOTHING, so DemoKpiSeeder can run this file
-- again on a database that already has it.
--
-- The values are chosen so every status appears somewhere: on track, at risk
-- (within 10%), off track and no data. Computed KPIs have no rows here — their
-- values come from the work the other seed files already hold.

INSERT INTO kpis (id, organization_id, name, description, subject_type, subject_id, source, unit, direction, target, period, created_by_membership_id) VALUES
 -- Groups. Ahmad heads Engineering; Lisa runs Marketing; Rina keeps QA's.
 ('01900009-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','Releases shipped',
  'Production releases of the API and platform services.','team','01900000-0000-7000-8000-000000000802',
  'manual','releases','higher',4,'month','01900000-0000-7000-8000-000000000202'),
 ('01900009-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','Customer-reported bugs',
  'Bugs in the web client reported by customers through support.','team','01900000-0000-7000-8000-000000000801',
  'manual','bugs','lower',3,'week','01900000-0000-7000-8000-000000000202'),
 ('01900009-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','Campaign sign-ups',
  'New sign-ups attributed to this month''s campaigns.','team','01900000-0000-7000-8000-000000000804',
  'manual','sign-ups','higher',500,'month','01900000-0000-7000-8000-000000000201'),
 ('01900009-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','Escaped defects',
  'Defects found in production that testing should have caught.','department','01900000-0000-7000-8000-000000000605',
  'manual','defects','lower',2,'month','01900000-0000-7000-8000-000000000201'),
 ('01900009-0000-7000-8000-000000000005','01900000-0000-7000-8000-0000000000ac','Items finished',
  'Work moved to Done across Engineering and Quality Assurance.','department','01900000-0000-7000-8000-000000000601',
  'throughput','items','higher',15,'week','01900000-0000-7000-8000-000000000202'),
 ('01900009-0000-7000-8000-000000000006','01900000-0000-7000-8000-0000000000ac','Cycle time',
  'How long 85% of finished items took, from first start to done.','project','01900003-0000-7000-8000-000000000001',
  'cycle_time_p85','hours','lower',120,'month','01900000-0000-7000-8000-000000000202'),
 ('01900009-0000-7000-8000-000000000007','01900000-0000-7000-8000-0000000000ac','Finished on time',
  'Share of dated items finished by their due date.','project','01900003-0000-7000-8000-000000000001',
  'on_time_rate','percent','higher',85,'month','01900000-0000-7000-8000-000000000202'),
 -- People. Set by Ahmad, their manager; seen only by them and the people above
 -- them (ADR 0062, "Per person"). David reports his own manual values.
 ('01900009-0000-7000-8000-000000000008','01900000-0000-7000-8000-0000000000ac','Code reviews given',
  'Pull requests reviewed for other people.','person','01900000-0000-7000-8000-000000000204',
  'manual','reviews','higher',8,'month','01900000-0000-7000-8000-000000000202'),
 ('01900009-0000-7000-8000-000000000009','01900000-0000-7000-8000-0000000000ac','Items finished',
  'His own assigned work moved to Done.','person','01900000-0000-7000-8000-000000000204',
  'throughput','items','higher',6,'month','01900000-0000-7000-8000-000000000202'),
 ('01900009-0000-7000-8000-00000000000a','01900000-0000-7000-8000-0000000000ac','Test plans written',
  'Test plans ready before a feature reaches review.','person','01900000-0000-7000-8000-000000000205',
  'manual','plans','higher',5,'month','01900000-0000-7000-8000-000000000202')
ON CONFLICT (id) DO NOTHING;

-- Monthly entries: n months ago (0 = this month, so far).
INSERT INTO kpi_entries (id, organization_id, kpi_id, period_start, value, note, entered_by_membership_id)
SELECT md5(v.kpi || ':' || v.n)::uuid,
       '01900000-0000-7000-8000-0000000000ac',
       v.kpi::uuid,
       (date_trunc('month', now() AT TIME ZONE 'UTC') - make_interval(months => v.n))::date,
       v.value, v.note, v.who::uuid
  FROM (VALUES
    -- Releases shipped (target ≥ 4): mostly on track, one bad month, this
    -- month at risk so far.
    ('01900009-0000-7000-8000-000000000001', 5, 4, '',                             '01900000-0000-7000-8000-000000000202'),
    ('01900009-0000-7000-8000-000000000001', 4, 5, '',                             '01900000-0000-7000-8000-000000000202'),
    ('01900009-0000-7000-8000-000000000001', 3, 2, 'Release freeze for the audit', '01900000-0000-7000-8000-000000000202'),
    ('01900009-0000-7000-8000-000000000001', 2, 4, '',                             '01900000-0000-7000-8000-000000000202'),
    ('01900009-0000-7000-8000-000000000001', 1, 6, 'Two hotfixes',                 '01900000-0000-7000-8000-000000000202'),
    ('01900009-0000-7000-8000-000000000001', 0, 3.7, 'Month not over',             '01900000-0000-7000-8000-000000000202'),
    -- Campaign sign-ups (target ≥ 500): climbing, off track this month.
    ('01900009-0000-7000-8000-000000000003', 5, 310, '',                           '01900000-0000-7000-8000-000000000207'),
    ('01900009-0000-7000-8000-000000000003', 4, 380, '',                           '01900000-0000-7000-8000-000000000207'),
    ('01900009-0000-7000-8000-000000000003', 3, 455, '',                           '01900000-0000-7000-8000-000000000207'),
    ('01900009-0000-7000-8000-000000000003', 2, 520, 'Webinar series',             '01900000-0000-7000-8000-000000000207'),
    ('01900009-0000-7000-8000-000000000003', 1, 610, '',                           '01900000-0000-7000-8000-000000000207'),
    ('01900009-0000-7000-8000-000000000003', 0, 240, 'Campaign starts mid-month',  '01900000-0000-7000-8000-000000000207'),
    -- Escaped defects (target ≤ 2): off track two months ago, on track now.
    ('01900009-0000-7000-8000-000000000004', 5, 3, '',                             '01900000-0000-7000-8000-000000000205'),
    ('01900009-0000-7000-8000-000000000004', 4, 2, '',                             '01900000-0000-7000-8000-000000000205'),
    ('01900009-0000-7000-8000-000000000004', 3, 4, 'Payment edge cases',           '01900000-0000-7000-8000-000000000205'),
    ('01900009-0000-7000-8000-000000000004', 2, 2, '',                             '01900000-0000-7000-8000-000000000205'),
    ('01900009-0000-7000-8000-000000000004', 1, 1, '',                             '01900000-0000-7000-8000-000000000205'),
    ('01900009-0000-7000-8000-000000000004', 0, 0, '',                             '01900000-0000-7000-8000-000000000205'),
    -- David: code reviews (target ≥ 8), reported by David himself.
    ('01900009-0000-7000-8000-000000000008', 5, 6, '',                             '01900000-0000-7000-8000-000000000204'),
    ('01900009-0000-7000-8000-000000000008', 4, 9, '',                             '01900000-0000-7000-8000-000000000204'),
    ('01900009-0000-7000-8000-000000000008', 3, 8, '',                             '01900000-0000-7000-8000-000000000204'),
    ('01900009-0000-7000-8000-000000000008', 2, 11, 'Covered for Sarah on leave',  '01900000-0000-7000-8000-000000000204'),
    ('01900009-0000-7000-8000-000000000008', 1, 7.5, '',                           '01900000-0000-7000-8000-000000000204'),
    -- Maya: test plans (target ≥ 5). This month left empty on purpose: "no
    -- data" is a state the screen has to show too.
    ('01900009-0000-7000-8000-00000000000a', 3, 4, '',                             '01900000-0000-7000-8000-000000000205'),
    ('01900009-0000-7000-8000-00000000000a', 2, 5, '',                             '01900000-0000-7000-8000-000000000205'),
    ('01900009-0000-7000-8000-00000000000a', 1, 6, '',                             '01900000-0000-7000-8000-000000000205')
  ) AS v(kpi, n, value, note, who)
ON CONFLICT (kpi_id, period_start) DO NOTHING;

-- Weekly entries: n weeks ago, weeks from Monday (date_trunc('week') is ISO).
INSERT INTO kpi_entries (id, organization_id, kpi_id, period_start, value, note, entered_by_membership_id)
SELECT md5(v.kpi || ':w' || v.n)::uuid,
       '01900000-0000-7000-8000-0000000000ac',
       v.kpi::uuid,
       (date_trunc('week', now() AT TIME ZONE 'UTC') - make_interval(weeks => v.n))::date,
       v.value, v.note, '01900000-0000-7000-8000-000000000203'::uuid
  FROM (VALUES
    -- Customer-reported bugs (target ≤ 3): a bad release, then recovery.
    ('01900009-0000-7000-8000-000000000002', 11, 2, ''),
    ('01900009-0000-7000-8000-000000000002', 10, 3, ''),
    ('01900009-0000-7000-8000-000000000002',  9, 6, 'Board release regression'),
    ('01900009-0000-7000-8000-000000000002',  8, 5, ''),
    ('01900009-0000-7000-8000-000000000002',  7, 3, ''),
    ('01900009-0000-7000-8000-000000000002',  6, 2, ''),
    ('01900009-0000-7000-8000-000000000002',  5, 1, ''),
    ('01900009-0000-7000-8000-000000000002',  4, 3, ''),
    ('01900009-0000-7000-8000-000000000002',  3, 4, ''),
    ('01900009-0000-7000-8000-000000000002',  2, 2, ''),
    ('01900009-0000-7000-8000-000000000002',  1, 3, ''),
    ('01900009-0000-7000-8000-000000000002',  0, 1, 'Week not over')
  ) AS v(kpi, n, value, note)
ON CONFLICT (kpi_id, period_start) DO NOTHING;

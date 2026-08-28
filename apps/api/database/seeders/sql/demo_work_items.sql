-- Demo seed, Phase 3 — work items.
--
-- Generated procedurally but DETERMINISTICALLY: the distribution is derived
-- from the loop index, never from random(). The same seed produces byte-
-- identical data on every machine and every test run, because random fixtures
-- hide bugs that only appear on particular values (docs/11 §3).
--
-- Story items with fixed IDs follow the generated bulk; those are the ones the
-- tests and the screenshots refer to by name.

BEGIN;

DO $seed$
DECLARE
    acme      uuid := '01900000-0000-7000-8000-0000000000ac';
    wf        uuid := '01900001-0000-7000-8000-000000000001';

    -- state id / category pairs, indexed 0..6
    state_ids   uuid[] := ARRAY[
        '01900002-0000-7000-8000-000000000001',  -- backlog
        '01900002-0000-7000-8000-000000000002',  -- todo
        '01900002-0000-7000-8000-000000000003',  -- in_progress
        '01900002-0000-7000-8000-000000000004',  -- in_review
        '01900002-0000-7000-8000-000000000006',  -- completed
        '01900002-0000-7000-8000-000000000007',  -- blocked
        '01900002-0000-7000-8000-000000000006'   -- completed
    ];
    state_cats  text[] := ARRAY[
        'backlog','todo','in_progress','in_review','done','blocked','done'
    ];

    people    uuid[] := ARRAY[
        '01900000-0000-7000-8000-000000000203',  -- Sarah   (frontend)
        '01900000-0000-7000-8000-000000000204',  -- David   (backend, over-committed)
        '01900000-0000-7000-8000-000000000205',  -- Maya    (QA)
        '01900000-0000-7000-8000-000000000206',  -- Budi    (design, part-time)
        '01900000-0000-7000-8000-000000000207'   -- Lisa    (marketing)
    ];

    priorities text[] := ARRAY['low','medium','medium','high','urgent'];

    -- 40 titles rather than 15. With 15, a person's My Work list showed the
    -- same sentence three times, which reads as generated data and makes the
    -- screen impossible to evaluate — the eye stops distinguishing rows.
    titles text[] := ARRAY[
        'Extract assignment history into its own table',
        'Add tenant scope regression test for %s',
        'Fix N+1 on the project board query',
        'Migrate legacy scheduler jobs',
        'Write OpenAPI examples for work item endpoints',
        'Reduce first-load bundle on the board route',
        'Handle expired presigned upload URLs',
        'Backfill state_category on historical rows',
        'Define audit log retention policy',
        'Improve empty state copy on My Work',
        'Add keyboard shortcut for status change',
        'Cache permission resolution per membership',
        'Rate limit the search endpoint',
        'Timezone handling on due date pickers',
        'Virtualize the work item list above 100 rows',
        'Split the %s deployment pipeline in two',
        'Move attachment scanning onto the low-priority queue',
        'Add a partial index for the overdue scan',
        'Replace the legacy CSV export with a queued job',
        'Document the fractional ordering scheme',
        'Handle clock skew in session expiry checks',
        'Add a dry-run mode to the workflow rule engine',
        'Prune expired sessions on a schedule',
        'Fix focus trap on the assignment dialog',
        'Support quoted phrases in global search',
        'Set up read replica routing for reports',
        'Add correlation IDs to the activity timeline',
        'Reduce cold start time on the %s worker',
        'Write a runbook for partition maintenance',
        'Recalculate project progress after bulk edits',
        'Tighten the upload MIME allowlist',
        'Add an unaccepted-assignment reminder',
        'Investigate slow board render on large projects',
        'Normalise department codes on import',
        'Add optimistic locking to milestone updates',
        'Review the notification digest cadence',
        'Backfill missing employee capacity values',
        'Handle deleted users in the mention resolver',
        'Add a health check for the queue worker',
        'Clarify the difference between activity and audit logs'
    ];

    proj       record;
    i          integer;
    idx        integer;
    state_idx  integer;
    assignee   uuid;
    item_id    uuid;
    due        timestamptz;
    est        numeric;
    seq        integer := 0;
BEGIN
    FOR proj IN
        SELECT * FROM (VALUES
            ('01900003-0000-7000-8000-000000000001'::uuid, 'ENG', 58, 0),
            ('01900003-0000-7000-8000-000000000002'::uuid, 'WEB', 34, 1),
            ('01900003-0000-7000-8000-000000000003'::uuid, 'MKT', 14, 2),
            ('01900003-0000-7000-8000-000000000004'::uuid, 'LEG', 22, 3),
            ('01900003-0000-7000-8000-000000000005'::uuid, 'FIN',  9, 4)
        ) AS t(project_id, prefix, count, offset_seed)
    LOOP
        FOR i IN 1..proj.count LOOP
            seq := seq + 1;
            idx := i + proj.offset_seed;

            -- Legacy Migration is finished: every item is done. Everything else
            -- gets a spread across the workflow.
            IF proj.prefix = 'LEG' THEN
                state_idx := 4;
            ELSIF proj.prefix = 'MKT' THEN
                -- Not started: backlog and todo only.
                state_idx := idx % 2;
            ELSE
                state_idx := idx % 7;
            END IF;

            assignee := people[(idx % 5) + 1];

            -- Unassigned work exists and must appear in dashboards as a gap.
            IF idx % 11 = 0 THEN
                assignee := NULL;
            END IF;

            -- Roughly one in six items has no estimate. The workload bar must
            -- flag these rather than silently treat them as zero (docs/02 §11).
            est := CASE WHEN idx % 6 = 0 THEN NULL
                        ELSE ((idx % 5) + 1) * 2.0 END;

            due := CASE
                WHEN state_cats[state_idx + 1] = 'done'
                    THEN now() - ((idx % 60) || ' days')::interval
                -- Every 9th open item is overdue: the "needs attention"
                -- section has nothing to show unless overdue work exists.
                WHEN idx % 9 = 0
                    THEN now() - ((idx % 7) + 1 || ' days')::interval
                WHEN idx % 4 = 0
                    THEN date_trunc('day', now()) + interval '17 hours'
                ELSE now() + ((idx % 30) || ' days')::interval
            END;

            item_id := ('01900010-0000-7000-8000-' || lpad(seq::text, 12, '0'))::uuid;

            INSERT INTO work_items (
                id, organization_id, type, reference, title, description,
                project_id, milestone_id, created_by_membership_id,
                workflow_id, workflow_state_id, state_category,
                priority, start_date, due_at, estimate_hours,
                position, completed_at, created_at
            ) VALUES (
                item_id, acme, 'task',
                proj.prefix || '-' || i,
                CASE
                    WHEN titles[(idx % 40) + 1] LIKE '%\%s%'
                        THEN format(titles[(idx % 40) + 1], proj.prefix)
                    ELSE titles[(idx % 40) + 1]
                END,
                'Seeded work item for ' || proj.prefix || '. Replace with real content.',
                proj.project_id,
                NULL,
                '01900000-0000-7000-8000-000000000202',
                wf, state_ids[state_idx + 1], state_cats[state_idx + 1],
                priorities[(idx % 5) + 1],
                -- start_date must never fall after due_at: the CHECK constraint
                -- enforces it, and a seed that violates its own schema is a
                -- seed nobody trusts.
                LEAST((now() - ((idx % 40) || ' days')::interval)::date, due::date),
                due,
                est,
                i * 1000,
                CASE WHEN state_cats[state_idx + 1] = 'done'
                     THEN now() - ((idx % 50) || ' days')::interval END,
                now() - ((idx % 90) + 5 || ' days')::interval
            );

            IF assignee IS NOT NULL THEN
                INSERT INTO work_item_assignments (
                    id, organization_id, work_item_id, membership_id, role,
                    is_primary, assigned_by_membership_id, assigned_at, accepted_at
                ) VALUES (
                    ('01900011-0000-7000-8000-' || lpad(seq::text, 12, '0'))::uuid,
                    acme, item_id, assignee, 'assignee', true,
                    '01900000-0000-7000-8000-000000000202',
                    now() - ((idx % 30) + 1 || ' days')::interval,
                    -- Every 7th assignment is unaccepted: "assigned but not
                    -- acknowledged" is a distinct state a manager must see.
                    CASE WHEN idx % 7 <> 0
                         THEN now() - ((idx % 30) || ' days')::interval END
                );
            END IF;
        END LOOP;
    END LOOP;
END
$seed$;

-- ── David is over-committed this week ────────────────────────────────────────
-- Not an accident of the generator: the manager dashboard's most important job
-- is surfacing this, so the seed guarantees it exists.
UPDATE work_items SET
    estimate_hours = 12,
    due_at = date_trunc('week', now()) + interval '4 days 17 hours',
    state_category = 'in_progress',
    workflow_state_id = '01900002-0000-7000-8000-000000000003',
    completed_at = NULL
WHERE id IN (
    SELECT wi.id
      FROM work_items wi
      JOIN work_item_assignments a
        ON a.work_item_id = wi.id AND a.unassigned_at IS NULL
     WHERE a.membership_id = '01900000-0000-7000-8000-000000000204'
       AND wi.state_category <> 'done'
     ORDER BY wi.reference
     LIMIT 4
);

-- ── shaping the workload picture ─────────────────────────────────────────────
-- The generator produces a plausible spread, but the manager dashboard has one
-- job — showing who is over-committed and who is not — and a seed that leaves
-- that to chance cannot be used to check the screen actually works.
--
-- Target picture: David over capacity, Budi (part-time) close to his 24 h,
-- Sarah and Maya with room, Lisa clearly light.

-- Budi is part-time; the generator gave him a full-timer's queue. Move the
-- excess to Sarah, who has room. Reassignment CLOSES the old row and opens a
-- new one — even in the seed, because that is the only way the history is real.
WITH excess AS (
    SELECT a.id, a.work_item_id
      FROM work_item_assignments a
      JOIN work_items wi ON wi.id = a.work_item_id
     WHERE a.membership_id = '01900000-0000-7000-8000-000000000206'
       AND a.unassigned_at IS NULL AND a.role = 'assignee'
       AND wi.state_category IN ('todo','in_progress','in_review')
     ORDER BY wi.due_at
     OFFSET 4
)
UPDATE work_item_assignments a
   SET unassigned_at = now() - interval '2 days',
       unassigned_reason = 'Rebalanced — Budi is part-time at 24 h/week'
  FROM excess
 WHERE a.id = excess.id;

INSERT INTO work_item_assignments
 (id, organization_id, work_item_id, membership_id, role, is_primary,
  assigned_by_membership_id, assigned_at, accepted_at)
SELECT ('0190001b-0000-7000-8000-' || lpad(row_number() OVER (ORDER BY a.work_item_id)::text, 12, '0'))::uuid,
       '01900000-0000-7000-8000-0000000000ac', a.work_item_id,
       '01900000-0000-7000-8000-000000000203', 'assignee', true,
       '01900000-0000-7000-8000-000000000202',
       now() - interval '2 days', now() - interval '2 days'
  FROM work_item_assignments a
 WHERE a.membership_id = '01900000-0000-7000-8000-000000000206'
   AND a.unassigned_reason = 'Rebalanced — Budi is part-time at 24 h/week'
   AND NOT EXISTS (
        SELECT 1 FROM work_item_assignments b
         WHERE b.work_item_id = a.work_item_id
           AND b.unassigned_at IS NULL AND b.role = 'assignee' AND b.is_primary
   );

-- Push David clearly over his 40 h so the "over-committed" state is always
-- present on the manager dashboard.
--
-- start_date moves to this week as well, and that is the point rather than a
-- convenience: capacity is consumed by the OVERLAP between an item's span and
-- the week (docs/02 §11). A 14 h item spanning six weeks costs about two hours
-- of this week — so shortening the span is what actually creates the
-- over-commitment, and getting that wrong in the seed would have hidden a
-- calculation this dashboard depends on.
UPDATE work_items SET
    estimate_hours = 11,
    start_date = GREATEST(date_trunc('week', now())::date, start_date),
    due_at = date_trunc('week', now()) + interval '4 days 17 hours'
WHERE id IN (
    SELECT wi.id FROM work_items wi
      JOIN work_item_assignments a ON a.work_item_id = wi.id AND a.unassigned_at IS NULL
     WHERE a.membership_id = '01900000-0000-7000-8000-000000000204'
       AND a.role = 'assignee'
       AND wi.state_category IN ('todo','in_progress','in_review')
     ORDER BY wi.reference LIMIT 3
);

-- ── work that belongs to no project ──────────────────────────────────────────
-- project_id IS NULL is a first-class case, not an edge case: any query that
-- assumes otherwise is a bug (docs/02 §5).
INSERT INTO work_items
 (id, organization_id, type, reference, title, description, project_id, created_by_membership_id,
  workflow_id, workflow_state_id, state_category, priority, due_at, estimate_hours, position, created_at)
VALUES
 ('01900012-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','request','REQ-1',
  'Laptop replacement for the QA team','Two machines are out of warranty.',NULL,
  '01900000-0000-7000-8000-000000000205','01900001-0000-7000-8000-000000000002',
  '01900002-0000-7000-8000-000000000011','todo','medium', now() + interval '5 days', 2, 1000, now() - interval '3 days'),
 ('01900012-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','request','REQ-2',
  'Access to the production metrics dashboard','',NULL,
  '01900000-0000-7000-8000-000000000203','01900001-0000-7000-8000-000000000002',
  '01900002-0000-7000-8000-000000000012','in_progress','low', now() + interval '2 days', 1, 2000, now() - interval '6 days'),
 ('01900012-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','incident','OPS-1',
  'Checkout latency spike between 14:00 and 14:20','p99 tripled; suspected connection pool exhaustion.',NULL,
  '01900000-0000-7000-8000-000000000204','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000003','in_progress','urgent', now() - interval '1 day', 6, 3000, now() - interval '2 days'),
 ('01900012-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','operational','OPS-2',
  'Weekly deployment checklist','',NULL,
  '01900000-0000-7000-8000-000000000202','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000002','todo','medium', date_trunc('day', now()) + interval '18 hours', 1, 4000, now() - interval '1 day');

INSERT INTO work_item_assignments
 (id, organization_id, work_item_id, membership_id, role, is_primary, assigned_by_membership_id, assigned_at, accepted_at)
VALUES
 ('01900013-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900012-0000-7000-8000-000000000003','01900000-0000-7000-8000-000000000204','assignee',true,'01900000-0000-7000-8000-000000000202', now()-interval '2 days', now()-interval '2 days'),
 ('01900013-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','01900012-0000-7000-8000-000000000004','01900000-0000-7000-8000-000000000205','assignee',true,'01900000-0000-7000-8000-000000000202', now()-interval '1 day', NULL);

-- ── the reassignment narrative ───────────────────────────────────────────────
-- The exact story docs/02 §6 describes, as data:
--   assigned to David → David started → reassigned to Sarah (with a reason)
--   → Sarah submitted → changes requested → Sarah resubmitted.
-- Every step is a row. None of it is reconstructed from a status column.
INSERT INTO work_items
 (id, organization_id, type, reference, title, description, project_id, milestone_id,
  created_by_membership_id, workflow_id, workflow_state_id, state_category,
  priority, start_date, due_at, estimate_hours, position, created_at)
VALUES
 ('01900014-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','task','ENG-142',
  'Implement assignment history so reassignment is never lost',
  E'Assignment must keep full history rather than a pivot table.\n\n- Close rows instead of deleting them\n- One active primary assignee, enforced by a partial unique index\n- The timeline reads as a narrative, not a status field',
  '01900003-0000-7000-8000-000000000001','01900005-0000-7000-8000-000000000002',
  '01900000-0000-7000-8000-000000000202','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000004','in_review','high',
  (now() - interval '9 days')::date, date_trunc('day', now()) + interval '17 hours', 8, 500, now() - interval '12 days'),

 -- A 90-character title, on purpose: truncation must be designed, not discovered.
 ('01900014-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','task','ENG-143',
  'Investigate intermittent websocket disconnections observed on the board view during peak hours',
  '', '01900003-0000-7000-8000-000000000001', NULL,
  '01900000-0000-7000-8000-000000000202','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000007','blocked','high',
  (now() - interval '5 days')::date, now() + interval '3 days', 5, 600, now() - interval '6 days'),

 ('01900014-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','task','ENG-144',
  'Connection pool sizing for the new work item queries','',
  '01900003-0000-7000-8000-000000000001', NULL,
  '01900000-0000-7000-8000-000000000202','01900001-0000-7000-8000-000000000001',
  '01900002-0000-7000-8000-000000000003','in_progress','urgent',
  (now() - interval '4 days')::date, now() + interval '1 day', 4, 700, now() - interval '5 days');

-- Subtasks: hierarchy must be exercised by the seed, not only by tests.
INSERT INTO work_items
 (id, organization_id, type, reference, title, project_id, parent_id, created_by_membership_id,
  workflow_id, workflow_state_id, state_category, priority, due_at, estimate_hours, position,
  completed_at, created_at)
VALUES
 ('01900014-0000-7000-8000-000000000011','01900000-0000-7000-8000-0000000000ac','task','ENG-145','Schema and migration',
  '01900003-0000-7000-8000-000000000001','01900014-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000203',
  '01900001-0000-7000-8000-000000000001','01900002-0000-7000-8000-000000000006','done','high',
  now()-interval '6 days', 3, 100, now()-interval '6 days', now()-interval '11 days'),
 ('01900014-0000-7000-8000-000000000012','01900000-0000-7000-8000-0000000000ac','task','ENG-146','Repository layer',
  '01900003-0000-7000-8000-000000000001','01900014-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000203',
  '01900001-0000-7000-8000-000000000001','01900002-0000-7000-8000-000000000006','done','medium',
  now()-interval '3 days', 3, 200, now()-interval '3 days', now()-interval '11 days'),
 ('01900014-0000-7000-8000-000000000013','01900000-0000-7000-8000-0000000000ac','task','ENG-147','History timeline component',
  '01900003-0000-7000-8000-000000000001','01900014-0000-7000-8000-000000000001','01900000-0000-7000-8000-000000000203',
  '01900001-0000-7000-8000-000000000001','01900002-0000-7000-8000-000000000003','in_progress','medium',
  now()+interval '1 day', 2, 300, NULL, now()-interval '11 days');

-- The assignment history for ENG-142. Three rows, two of them closed.
INSERT INTO work_item_assignments
 (id, organization_id, work_item_id, membership_id, role, is_primary,
  assigned_by_membership_id, assigned_at, accepted_at, unassigned_at, unassigned_reason)
VALUES
 -- 1. David picks it up, then hands it over.
 ('01900015-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900014-0000-7000-8000-000000000001',
  '01900000-0000-7000-8000-000000000204','assignee',true,'01900000-0000-7000-8000-000000000202',
  now()-interval '12 days', now()-interval '12 days', now()-interval '9 days',
  'Reassigned to Sarah — David pulled onto the checkout incident'),
 -- 2. Sarah takes over and is the current assignee.
 ('01900015-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','01900014-0000-7000-8000-000000000001',
  '01900000-0000-7000-8000-000000000203','assignee',true,'01900000-0000-7000-8000-000000000202',
  now()-interval '9 days', now()-interval '9 days', NULL, NULL),
 -- 3. Ahmad is the reviewer throughout — a separate role, not a separate table.
 ('01900015-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','01900014-0000-7000-8000-000000000001',
  '01900000-0000-7000-8000-000000000202','reviewer',false,'01900000-0000-7000-8000-000000000202',
  now()-interval '12 days', now()-interval '12 days', NULL, NULL),

 ('01900015-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','01900014-0000-7000-8000-000000000002',
  '01900000-0000-7000-8000-000000000204','assignee',true,'01900000-0000-7000-8000-000000000202',
  now()-interval '6 days', now()-interval '6 days', NULL, NULL),
 ('01900015-0000-7000-8000-000000000005','01900000-0000-7000-8000-0000000000ac','01900014-0000-7000-8000-000000000003',
  '01900000-0000-7000-8000-000000000204','assignee',true,'01900000-0000-7000-8000-000000000202',
  now()-interval '5 days', now()-interval '5 days', NULL, NULL),
 ('01900015-0000-7000-8000-000000000011','01900000-0000-7000-8000-0000000000ac','01900014-0000-7000-8000-000000000013',
  '01900000-0000-7000-8000-000000000203','assignee',true,'01900000-0000-7000-8000-000000000202',
  now()-interval '4 days', now()-interval '4 days', NULL, NULL);

-- ── dependencies ─────────────────────────────────────────────────────────────
-- ENG-143 is blocked by ENG-144: "blocked" is not just a status, it points at
-- the thing doing the blocking, which is what makes it actionable.
INSERT INTO work_item_dependencies
 (id, organization_id, work_item_id, depends_on_work_item_id, type, created_by, created_at)
VALUES
 ('01900016-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac',
  '01900014-0000-7000-8000-000000000002','01900014-0000-7000-8000-000000000003','blocks',
  '01900000-0000-7000-8000-000000000202', now()-interval '4 days'),
 ('01900016-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac',
  '01900014-0000-7000-8000-000000000001','01900014-0000-7000-8000-000000000013','blocks',
  '01900000-0000-7000-8000-000000000203', now()-interval '3 days');

-- ── comments and mentions ────────────────────────────────────────────────────
INSERT INTO comments
 (id, organization_id, commentable_type, commentable_id, author_membership_id, body_markdown, body_html, created_at)
VALUES
 ('01900017-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','work_item','01900014-0000-7000-8000-000000000001',
  '01900000-0000-7000-8000-000000000203',
  '@Ahmad Rizal the history table needs an index on membership_id — the My Work query is doing a sequential scan without it.',
  '<p><span class="mention">@Ahmad Rizal</span> the history table needs an index on membership_id — the My Work query is doing a sequential scan without it.</p>',
  now()-interval '2 days'),
 ('01900017-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','work_item','01900014-0000-7000-8000-000000000001',
  '01900000-0000-7000-8000-000000000202',
  'Good catch. Add it as a partial index on `unassigned_at IS NULL` — the historical rows never appear in that query.',
  '<p>Good catch. Add it as a partial index on <code>unassigned_at IS NULL</code> — the historical rows never appear in that query.</p>',
  now()-interval '2 days' + interval '40 minutes'),
 ('01900017-0000-7000-8000-000000000003','01900000-0000-7000-8000-0000000000ac','work_item','01900014-0000-7000-8000-000000000001',
  '01900000-0000-7000-8000-000000000203',
  'Done and submitted for review.',
  '<p>Done and submitted for review.</p>',
  now()-interval '4 hours'),
 ('01900017-0000-7000-8000-000000000004','01900000-0000-7000-8000-0000000000ac','work_item','01900012-0000-7000-8000-000000000003',
  '01900000-0000-7000-8000-000000000204',
  'Pool size was 20 against 8 workers. Raised to 60 and latency recovered within a minute.',
  '<p>Pool size was 20 against 8 workers. Raised to 60 and latency recovered within a minute.</p>',
  now()-interval '20 hours');

INSERT INTO mentions (id, organization_id, comment_id, mentioned_membership_id, read_at) VALUES
 ('01900018-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900017-0000-7000-8000-000000000001',
  '01900000-0000-7000-8000-000000000202', NULL);

-- ── tag application ──────────────────────────────────────────────────────────
INSERT INTO taggables (organization_id, tag_id, taggable_type, taggable_id) VALUES
 ('01900000-0000-7000-8000-0000000000ac','01900006-0000-7000-8000-000000000001','work_item','01900012-0000-7000-8000-000000000003'),
 ('01900000-0000-7000-8000-0000000000ac','01900006-0000-7000-8000-000000000003','work_item','01900012-0000-7000-8000-000000000003'),
 ('01900000-0000-7000-8000-0000000000ac','01900006-0000-7000-8000-000000000002','work_item','01900014-0000-7000-8000-000000000001');

-- ── the other tenant's work ──────────────────────────────────────────────────
-- One row is enough: its entire purpose is to be invisible from Acme.
INSERT INTO work_items
 (id, organization_id, type, reference, title, project_id, created_by_membership_id,
  workflow_id, workflow_state_id, state_category, priority, position, created_at)
VALUES
 ('01900019-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000b0','task','GBX-1',
  'Globex confidential work — must never appear in an Acme response',
  '01900003-0000-7000-8000-000000000009','01900000-0000-7000-8000-000000000301',
  '01900001-0000-7000-8000-000000000009','01900002-0000-7000-8000-000000000019','todo','medium',1000, now());

-- ── saved views ──────────────────────────────────────────────────────────────
INSERT INTO saved_views (id, organization_id, owner_membership_id, scope, name, surface, definition, position) VALUES
 ('0190001a-0000-7000-8000-000000000001','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000202','team',
  'Team — overdue and unassigned','work_items',
  '{"filter":{"state_category":["todo","in_progress","in_review"],"overdue":true},"sort":"-due_at","group_by":"assignee"}', 0),
 ('0190001a-0000-7000-8000-000000000002','01900000-0000-7000-8000-0000000000ac','01900000-0000-7000-8000-000000000203','personal',
  'My urgent work','work_items',
  '{"filter":{"assignee_id":"me","priority":["high","urgent"]},"sort":"due_at"}', 0);

COMMIT;

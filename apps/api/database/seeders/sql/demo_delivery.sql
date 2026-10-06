-- ── Delivery history (ADR 0062, "Delivery without a KPI") ───────────────────
-- Finished work for each person in the last twelve weeks, so the Delivery
-- panel on a person's page has something to show. Every state it can be in
-- appears for someone:
--
--   Sarah  — steady, mostly on time, a few without a due date
--   David  — fewer items, most of them late
--   Maya   — everything on time (100%)
--   Budi   — finished work, none of it with a due date ("no due dates")
--   Lisa   — work in earlier weeks, nothing in the last four (an empty list)
--   Ahmad  — a handful, for the manager's own page
--   Tono   — nothing at all
--
-- All of it lives in its own project, SUP "Support Desk", so it never shifts
-- the references or the counts of the projects the other seed files and the
-- tests depend on.
--
-- Everything is computed from today, and each item is keyed by the calendar
-- week it belongs to. Running the file again skips the weeks it already filled
-- and adds the ones that have passed since — it never deletes, because
-- work_item_transitions is append-only and refuses a DELETE outright.

INSERT INTO projects
 (id, organization_id, key, name, description, owner_membership_id, department_id, workflow_id,
  status, priority, visibility, start_date, end_date, budget_amount, budget_currency, archived_at)
VALUES
 ('01900003-0000-7000-8000-0000000000d1','01900000-0000-7000-8000-0000000000ac','SUP','Support Desk',
  'Small requests and fixes from across the company. Seeded as delivery history.',
  '01900000-0000-7000-8000-000000000202','01900000-0000-7000-8000-000000000601','01900001-0000-7000-8000-000000000001',
  'active','medium','internal', current_date - 120, NULL, NULL, NULL, NULL)
ON CONFLICT (id) DO NOTHING;

DO $delivery$
DECLARE
    acme          uuid := '01900000-0000-7000-8000-0000000000ac';
    sup           uuid := '01900003-0000-7000-8000-0000000000d1';
    wf            uuid := '01900001-0000-7000-8000-000000000001';
    s_backlog     uuid := '01900002-0000-7000-8000-000000000001';
    s_todo        uuid := '01900002-0000-7000-8000-000000000002';
    s_in_progress uuid := '01900002-0000-7000-8000-000000000003';
    s_in_review   uuid := '01900002-0000-7000-8000-000000000004';
    s_completed   uuid := '01900002-0000-7000-8000-000000000006';
    week_start    timestamptz := date_trunc('week', now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC';

    titles text[] := ARRAY[
        'Reset access for a new starter', 'Fix the broken link in the onboarding email',
        'Export last quarter''s invoices', 'Update the office Wi-Fi notice',
        'Add a column to the sales report', 'Investigate a slow page in the portal',
        'Renew the design tool licence', 'Correct a typo on the pricing page',
        'Merge duplicate customer records', 'Set up a shared calendar for the team',
        'Archive the old project folders', 'Answer a question about leave balances'
    ];

    -- membership, items per week (12 weeks ago → this week), late every n-th
    -- (0 = never), undated every n-th (0 = never, 1 = always)
    person record;
    w      integer;
    j      integer;
    n      integer := 0;
    last_ref integer;
    item   uuid;
    done_at   timestamptz;
    start_at  timestamptz;
    born_at   timestamptz;
    due       timestamptz;
    late      boolean;
    undated   boolean;
BEGIN
    -- New references continue after the highest SUP number already used.
    SELECT COALESCE(max(substring(reference from 5)::integer), 0) INTO last_ref
      FROM work_items
     WHERE organization_id = acme AND reference ~ '^SUP-[0-9]+$';

    FOR person IN
        SELECT * FROM (VALUES
            ('01900000-0000-7000-8000-000000000203'::uuid, ARRAY[2,3,2,4,3,2,3,4,3,2,3,1], 4, 5),  -- Sarah
            ('01900000-0000-7000-8000-000000000204'::uuid, ARRAY[1,0,2,1,1,2,0,1,2,1,1,1], 1, 0),  -- David: mostly late
            ('01900000-0000-7000-8000-000000000205'::uuid, ARRAY[1,2,1,2,2,1,2,2,1,2,2,1], 0, 0),  -- Maya
            ('01900000-0000-7000-8000-000000000206'::uuid, ARRAY[0,1,1,0,1,1,0,1,1,1,0,1], 0, 1),  -- Budi
            ('01900000-0000-7000-8000-000000000207'::uuid, ARRAY[2,1,2,1,2,1,1,2,0,0,0,0], 3, 0),  -- Lisa
            ('01900000-0000-7000-8000-000000000202'::uuid, ARRAY[0,1,0,1,0,0,1,0,1,0,1,0], 2, 0)   -- Ahmad
        ) AS p(membership, per_week, late_every, undated_every)
    LOOP
        FOR w IN 1..12 LOOP
            FOR j IN 1..person.per_week[w] LOOP
                n := n + 1;
                -- Keyed by the week's date, not its position: the same week
                -- is the same item however many times this runs.
                item := md5('delivery:' || person.membership || ':'
                            || (week_start - make_interval(weeks => 12 - w))::date || ':' || j)::uuid;

                IF EXISTS (SELECT 1 FROM work_items WHERE id = item) THEN
                    CONTINUE;
                END IF;

                last_ref := last_ref + 1;

                -- Spread through the week; this week's land in the hours
                -- already gone, never in the future.
                done_at := week_start - make_interval(weeks => 12 - w)
                           + make_interval(days => (j * 2 + n) % 5, hours => 9 + (n % 7));
                IF done_at > now() - interval '1 hour' THEN
                    done_at := now() - make_interval(hours => 2 * j + 1);
                END IF;

                start_at := done_at - make_interval(days => (n % 4) + 1, hours => (n % 5) * 3);
                born_at := start_at - make_interval(days => (n % 3) + 1);

                -- David: late except every third item, so his share is low but not zero.
                late := CASE
                    WHEN person.late_every = 0 THEN false
                    WHEN person.late_every = 1 THEN n % 3 <> 0
                    ELSE n % person.late_every = 0
                END;
                undated := person.undated_every = 1
                           OR (person.undated_every > 1 AND n % person.undated_every = 0);

                due := CASE
                    WHEN undated THEN NULL
                    WHEN late THEN done_at - make_interval(days => (n % 3) + 1)
                    ELSE done_at + make_interval(days => (n % 3) + 1)
                END;

                INSERT INTO work_items (
                    id, organization_id, type, reference, title, description,
                    project_id, milestone_id, created_by_membership_id,
                    workflow_id, workflow_state_id, state_category,
                    priority, start_date, due_at, estimate_hours,
                    position, completed_at, created_at
                ) VALUES (
                    item, acme, 'task', 'SUP-' || last_ref, titles[(n % 12) + 1],
                    'Seeded delivery history. Replace with real content.',
                    sup, NULL, '01900000-0000-7000-8000-000000000202',
                    wf, s_completed, 'done',
                    'medium',
                    LEAST(born_at::date, COALESCE(due::date, born_at::date)),
                    due, ((n % 4) + 1) * 2.0,
                    last_ref * 1000, done_at, born_at
                );

                INSERT INTO work_item_assignments (
                    id, organization_id, work_item_id, membership_id, role,
                    is_primary, assigned_by_membership_id, assigned_at, accepted_at
                ) VALUES (
                    md5('delivery-assignment:' || item)::uuid, acme, item, person.membership, 'assignee', true,
                    '01900000-0000-7000-8000-000000000202', born_at, born_at + interval '1 hour'
                );

                INSERT INTO work_item_transitions
                 (id, organization_id, work_item_id, from_state_id, to_state_id, from_category, to_category,
                  actor_membership_id, cause, causation_id, causation_depth, occurred_at)
                SELECT md5('delivery-transition:' || item || ':' || step)::uuid,
                       acme, item, from_id, to_id, from_cat, to_cat,
                       person.membership, 'user', md5('delivery-transition:' || item || ':' || step)::uuid, 0, at
                  FROM (VALUES
                        (1, NULL::uuid,    s_backlog,     NULL::text,    'backlog',     born_at),
                        (2, s_backlog,     s_todo,        'backlog',     'todo',        born_at + interval '2 hours'),
                        (3, s_todo,        s_in_progress, 'todo',        'in_progress', start_at),
                        (4, s_in_progress, s_in_review,   'in_progress', 'in_review',   done_at - interval '6 hours'),
                        (5, s_in_review,   s_completed,   'in_review',   'done',        done_at)
                  ) AS path(step, from_id, to_id, from_cat, to_cat, at);
            END LOOP;
        END LOOP;
    END LOOP;
END
$delivery$;

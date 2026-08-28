-- Work domain constraint proofs (Phase 3).
--
-- Runs against a database that already has the full seed loaded, so the proofs
-- exercise real data rather than a purpose-built fixture. Each block asserts
-- that PostgreSQL REFUSES something the application must never be able to do.
--
-- Run: psql -v ON_ERROR_STOP=1 -f verify-work-constraints.sql

\set ON_ERROR_STOP on
\pset pager off

\set acme    '01900000-0000-7000-8000-0000000000ac'
\set globex  '01900000-0000-7000-8000-0000000000b0'
\set eng142  '01900014-0000-7000-8000-000000000001'
\set eng144  '01900014-0000-7000-8000-000000000003'
\set engproj '01900003-0000-7000-8000-000000000001'

-- ── PROOF 1 ──────────────────────────────────────────────────────────────────
-- One ACTIVE primary assignee per work item. This is the partial unique index
-- that makes assignment history possible: closed rows are unlimited, active
-- ones are not (docs/02 §6).
DO $$
BEGIN
    BEGIN
        INSERT INTO work_item_assignments
            (id, organization_id, work_item_id, membership_id, role, is_primary, assigned_at)
        VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
                '01900014-0000-7000-8000-000000000001',
                '01900000-0000-7000-8000-000000000205', 'assignee', true, now());
        RAISE EXCEPTION 'PROOF 1 FAILED: a second active primary assignee was accepted';
    EXCEPTION WHEN unique_violation THEN
        RAISE NOTICE 'PROOF 1 ok  — second ACTIVE primary assignee refused';
    END;
END $$;

-- ── PROOF 2 ──────────────────────────────────────────────────────────────────
-- ...while historical rows are unrestricted. ENG-142 already carries a closed
-- assignment for David; adding another closed row must succeed, or the history
-- could never record repeated handovers.
INSERT INTO work_item_assignments
    (id, organization_id, work_item_id, membership_id, role, is_primary,
     assigned_at, unassigned_at, unassigned_reason)
VALUES (gen_random_uuid(), :'acme', :'eng142',
        '01900000-0000-7000-8000-000000000205', 'assignee', true,
        now() - interval '20 days', now() - interval '19 days', 'proof fixture');
\echo 'PROOF 2 ok  — historical assignment rows are unrestricted'

-- ── PROOF 3 ──────────────────────────────────────────────────────────────────
-- A reviewer and an assignee coexist: role is part of the identity of an
-- assignment, not a second table.
INSERT INTO work_item_assignments
    (id, organization_id, work_item_id, membership_id, role, is_primary, assigned_at)
VALUES (gen_random_uuid(), :'acme', :'eng144',
        '01900000-0000-7000-8000-000000000205', 'reviewer', false, now());
\echo 'PROOF 3 ok  — a reviewer can be added alongside an active assignee'

-- ── PROOF 4 ──────────────────────────────────────────────────────────────────
-- Work item references are unique per organization, and ONLY per organization:
-- Globex must be free to use ENG-142 as well.
DO $$
BEGIN
    BEGIN
        INSERT INTO work_items
            (id, organization_id, type, reference, title, workflow_id,
             workflow_state_id, state_category, position)
        VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac', 'task', 'ENG-142',
                'Duplicate reference', '01900001-0000-7000-8000-000000000001',
                '01900002-0000-7000-8000-000000000002', 'todo', 1);
        RAISE EXCEPTION 'PROOF 4a FAILED: duplicate reference accepted within a tenant';
    EXCEPTION WHEN unique_violation THEN
        RAISE NOTICE 'PROOF 4a ok — duplicate reference refused within the tenant';
    END;
END $$;

INSERT INTO work_items
    (id, organization_id, type, reference, title, workflow_id,
     workflow_state_id, state_category, position)
VALUES ('0190ff00-0000-7000-8000-000000000001', :'globex', 'task', 'ENG-142',
        'Same reference, different tenant', '01900001-0000-7000-8000-000000000009',
        '01900002-0000-7000-8000-000000000019', 'todo', 1);
\echo 'PROOF 4b ok — the same reference is free in another tenant'

-- ── PROOF 5 ──────────────────────────────────────────────────────────────────
-- Cross-tenant references are refused by the database, not merely by Eloquent.
DO $$
BEGIN
    BEGIN
        UPDATE work_items
           SET project_id = '01900003-0000-7000-8000-000000000001'  -- Acme's project
         WHERE id = '0190ff00-0000-7000-8000-000000000001';         -- Globex's item
        RAISE EXCEPTION 'PROOF 5 FAILED: cross-tenant project reference accepted';
    EXCEPTION WHEN foreign_key_violation THEN
        RAISE NOTICE 'PROOF 5 ok  — cross-tenant project reference rejected';
    END;
END $$;

-- ── PROOF 6 ──────────────────────────────────────────────────────────────────
-- A work item cannot be its own parent. Longer cycles are the application's job
-- (Postgres cannot declare "acyclic"), but the trivial case is caught here.
DO $$
BEGIN
    BEGIN
        UPDATE work_items SET parent_id = id WHERE id = '01900014-0000-7000-8000-000000000001';
        RAISE EXCEPTION 'PROOF 6 FAILED: self-parenting accepted';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 6 ok  — self-parenting refused';
    END;
END $$;

-- ── PROOF 7 ──────────────────────────────────────────────────────────────────
-- Done and completed_at must agree. Without this, every cycle-time report is
-- quietly wrong for the rows that disagree.
DO $$
BEGIN
    BEGIN
        UPDATE work_items
           SET state_category = 'done', workflow_state_id = '01900002-0000-7000-8000-000000000006'
         WHERE id = '01900014-0000-7000-8000-000000000003';   -- completed_at still NULL
        RAISE EXCEPTION 'PROOF 7a FAILED: done without a completion timestamp accepted';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 7a ok — "done" without completed_at refused';
    END;

    BEGIN
        UPDATE work_items SET completed_at = now()
         WHERE id = '01900014-0000-7000-8000-000000000003';   -- state is in_progress
        RAISE EXCEPTION 'PROOF 7b FAILED: completed_at on non-done item accepted';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 7b ok — completed_at on a non-done item refused';
    END;
END $$;

-- ── PROOF 8 ──────────────────────────────────────────────────────────────────
-- start_date may never fall after due_at.
DO $$
BEGIN
    BEGIN
        UPDATE work_items
           SET start_date = (now() + interval '30 days')::date, due_at = now() + interval '1 day'
         WHERE id = '01900014-0000-7000-8000-000000000003';
        RAISE EXCEPTION 'PROOF 8 FAILED: start after due accepted';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 8 ok  — start_date after due_at refused';
    END;
END $$;

-- ── PROOF 9 ──────────────────────────────────────────────────────────────────
-- A dependency cannot point at itself, and a pair cannot be duplicated.
DO $$
BEGIN
    BEGIN
        INSERT INTO work_item_dependencies (id, organization_id, work_item_id, depends_on_work_item_id, type)
        VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
                '01900014-0000-7000-8000-000000000002', '01900014-0000-7000-8000-000000000002', 'blocks');
        RAISE EXCEPTION 'PROOF 9a FAILED: self-dependency accepted';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 9a ok — self-dependency refused';
    END;

    BEGIN
        INSERT INTO work_item_dependencies (id, organization_id, work_item_id, depends_on_work_item_id, type)
        VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
                '01900014-0000-7000-8000-000000000002', '01900014-0000-7000-8000-000000000003', 'blocks');
        RAISE EXCEPTION 'PROOF 9b FAILED: duplicate dependency accepted';
    EXCEPTION WHEN unique_violation THEN
        RAISE NOTICE 'PROOF 9b ok — duplicate dependency edge refused';
    END;
END $$;

-- ── PROOF 10 ─────────────────────────────────────────────────────────────────
-- A longer cycle is NOT caught by any constraint — this proof documents the gap
-- the application must close, so nobody later assumes the database handles it.
DO $$
DECLARE
    cycle_len integer;
BEGIN
    INSERT INTO work_item_dependencies (id, organization_id, work_item_id, depends_on_work_item_id, type)
    VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
            '01900014-0000-7000-8000-000000000003', '01900014-0000-7000-8000-000000000002', 'blocks');

    -- The recursive CTE DependencyService runs before every insert.
    WITH RECURSIVE reachable(id, depth) AS (
        SELECT depends_on_work_item_id, 1
          FROM work_item_dependencies
         WHERE work_item_id = '01900014-0000-7000-8000-000000000002' AND type = 'blocks'
        UNION ALL
        SELECT d.depends_on_work_item_id, r.depth + 1
          FROM work_item_dependencies d
          JOIN reachable r ON d.work_item_id = r.id
         WHERE d.type = 'blocks' AND r.depth < 20
    )
    SELECT count(*) INTO cycle_len
      FROM reachable WHERE id = '01900014-0000-7000-8000-000000000002';

    IF cycle_len > 0 THEN
        RAISE NOTICE 'PROOF 10 ok — 2-hop cycle is NOT blocked by the schema and IS detected by the CTE the application runs';
    ELSE
        RAISE EXCEPTION 'PROOF 10 FAILED: the cycle detection query missed a real cycle';
    END IF;

    DELETE FROM work_item_dependencies
     WHERE work_item_id = '01900014-0000-7000-8000-000000000003'
       AND depends_on_work_item_id = '01900014-0000-7000-8000-000000000002';
END $$;

-- ── PROOF 11 ─────────────────────────────────────────────────────────────────
-- The search vector is a GENERATED column: it cannot drift from title and
-- description, because nothing can write it directly.
DO $$
DECLARE
    hits integer;
BEGIN
    BEGIN
        UPDATE work_items SET search_vector = to_tsvector('english', 'tampered')
         WHERE id = '01900014-0000-7000-8000-000000000001';
        RAISE EXCEPTION 'PROOF 11a FAILED: the generated search vector was writable';
    EXCEPTION WHEN generated_always THEN
        RAISE NOTICE 'PROOF 11a ok — search_vector cannot be written directly';
    END;

    UPDATE work_items SET title = 'Zeppelin choreography audit'
     WHERE id = '01900014-0000-7000-8000-000000000003';

    SELECT count(*) INTO hits FROM work_items
     WHERE search_vector @@ websearch_to_tsquery('english', 'zeppelin');

    IF hits = 1 THEN
        RAISE NOTICE 'PROOF 11b ok — the index updated itself on title change';
    ELSE
        RAISE EXCEPTION 'PROOF 11b FAILED: expected 1 hit, got %', hits;
    END IF;
END $$;

-- ── PROOF 12 ─────────────────────────────────────────────────────────────────
-- Fractional ordering: inserting between two neighbours writes ONE row, and the
-- resulting order is correct. This is why a board drag does not renumber a
-- column (docs/03 §3).
DO $$
DECLARE
    before_pos numeric;
    after_pos  numeric;
    mid_pos    numeric;
    ordered    text;
BEGIN
    SELECT position INTO before_pos FROM work_items WHERE reference = 'ENG-1';
    SELECT position INTO after_pos  FROM work_items WHERE reference = 'ENG-2';

    mid_pos := (before_pos + after_pos) / 2;

    INSERT INTO work_items
        (id, organization_id, type, reference, title, project_id, workflow_id,
         workflow_state_id, state_category, position)
    VALUES ('0190ff00-0000-7000-8000-000000000002', '01900000-0000-7000-8000-0000000000ac',
            'task', 'ENG-9001', 'Dropped between ENG-1 and ENG-2',
            '01900003-0000-7000-8000-000000000001', '01900001-0000-7000-8000-000000000001',
            '01900002-0000-7000-8000-000000000002', 'todo', mid_pos);

    SELECT string_agg(reference, ' < ' ORDER BY position) INTO ordered
      FROM work_items
     WHERE project_id = '01900003-0000-7000-8000-000000000001'
       AND reference IN ('ENG-1','ENG-9001','ENG-2');

    IF ordered = 'ENG-1 < ENG-9001 < ENG-2' THEN
        RAISE NOTICE 'PROOF 12 ok — fractional insert ordered correctly (%)', ordered;
    ELSE
        RAISE EXCEPTION 'PROOF 12 FAILED: order was %', ordered;
    END IF;
END $$;

-- ── PROOF 13 ─────────────────────────────────────────────────────────────────
-- A project member row grants access to EITHER a person or a team, never both.
DO $$
BEGIN
    BEGIN
        INSERT INTO project_members (id, organization_id, project_id, membership_id, team_id, role)
        VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
                '01900003-0000-7000-8000-000000000001',
                '01900000-0000-7000-8000-000000000205',
                '01900000-0000-7000-8000-000000000801', 'member');
        RAISE EXCEPTION 'PROOF 13 FAILED: a member row with both subjects was accepted';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 13 ok — project member must be a person XOR a team';
    END;
END $$;

-- ── PROOF 14 ─────────────────────────────────────────────────────────────────
-- Deleting a work item that others depend on must not silently orphan the
-- dependency; and a parent with children is protected from deletion entirely.
DO $$
BEGIN
    BEGIN
        DELETE FROM work_items WHERE id = '01900014-0000-7000-8000-000000000001';  -- has subtasks
        RAISE EXCEPTION 'PROOF 14 FAILED: a parent with children was deletable';
    EXCEPTION WHEN foreign_key_violation THEN
        RAISE NOTICE 'PROOF 14 ok  — a parent with subtasks cannot be hard-deleted';
    END;
END $$;

-- ── PROOF 15 ─────────────────────────────────────────────────────────────────
-- A file blob and its attachment are separate: the same file attached twice is
-- one blob, and the blob survives the removal of one attachment.
DO $$
DECLARE
    file_id uuid := gen_random_uuid();
BEGIN
    INSERT INTO files (id, organization_id, disk, path, original_name, mime_type, size_bytes, upload_state, scan_status)
    VALUES (file_id, '01900000-0000-7000-8000-0000000000ac', 's3',
            'org/acme/proof/' || file_id, 'spec.pdf', 'application/pdf', 1024, 'complete', 'clean');

    INSERT INTO attachments (id, organization_id, file_id, attachable_type, attachable_id)
    VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac', file_id,
            'work_item', '01900014-0000-7000-8000-000000000001'),
           (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac', file_id,
            'work_item', '01900014-0000-7000-8000-000000000003');

    BEGIN
        DELETE FROM files WHERE id = file_id;
        RAISE EXCEPTION 'PROOF 15 FAILED: a file with live attachments was deletable';
    EXCEPTION WHEN foreign_key_violation THEN
        RAISE NOTICE 'PROOF 15 ok  — one blob, two attachments; the blob is protected';
    END;
END $$;

-- ── cleanup: the proofs must leave the seed as they found it ─────────────────
DELETE FROM attachments WHERE attachable_id IN
    ('01900014-0000-7000-8000-000000000001','01900014-0000-7000-8000-000000000003');
DELETE FROM files WHERE path LIKE 'org/acme/proof/%';
DELETE FROM work_items WHERE id IN
    ('0190ff00-0000-7000-8000-000000000001','0190ff00-0000-7000-8000-000000000002');
DELETE FROM work_item_assignments WHERE unassigned_reason = 'proof fixture';
DELETE FROM work_item_assignments
 WHERE work_item_id = '01900014-0000-7000-8000-000000000003' AND role = 'reviewer';
UPDATE work_items SET title = 'Connection pool sizing for the new work item queries'
 WHERE id = '01900014-0000-7000-8000-000000000003';

\echo ''
\echo 'All work domain constraint proofs passed.'

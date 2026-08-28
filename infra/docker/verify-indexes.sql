-- Index shape proofs.
--
-- These assert that each hot query CAN use its intended index — not that it
-- does on the seed data. At 150 rows PostgreSQL correctly prefers a sequential
-- scan, so a naive EXPLAIN assertion would fail on a healthy database and
-- teach everyone to ignore it.
--
-- `enable_seqscan = off` removes the size question and leaves the one that
-- matters: does the index MATCH the predicate the application actually issues?
-- An index that cannot serve its query is dead weight, and it is silent —
-- nothing fails until the table is large enough to hurt.
--
-- Run: psql -v ON_ERROR_STOP=1 -f verify-indexes.sql

\set ON_ERROR_STOP on
\pset pager off

-- ANALYZE first, and not as a formality.
--
-- Immediately after a seed, PostgreSQL has no statistics for the new rows and
-- the planner falls back to defaults — which made this suite fail on a freshly
-- seeded database and pass on the same schema a minute later. A proof that
-- depends on when it is run is worse than no proof, so the statistics are made
-- current here rather than assumed.
ANALYZE work_items;
ANALYZE work_item_assignments;
ANALYZE projects;
ANALYZE project_members;
ANALYZE departments;

SET enable_seqscan = off;

CREATE OR REPLACE FUNCTION assert_uses_index(label text, expected_index text, query text)
RETURNS void AS $$
DECLARE
    plan text := '';
    line record;
BEGIN
    FOR line IN EXECUTE 'EXPLAIN ' || query LOOP
        plan := plan || line."QUERY PLAN" || ' ';
    END LOOP;

    IF position(expected_index IN plan) = 0 THEN
        RAISE EXCEPTION E'FAILED %: expected %\n  plan was: %', label, expected_index, plan;
    END IF;

    RAISE NOTICE 'ok  % → %', rpad(label, 34), expected_index;
END;
$$ LANGUAGE plpgsql;

\set acme '01900000-0000-7000-8000-0000000000ac'

-- The single most important index in the product: "my work", scoped and sorted.
-- Note `deleted_at IS NULL` — the index is PARTIAL, so the predicate the app
-- issues (Eloquent's SoftDeletes adds it) is what makes the index eligible.
SELECT assert_uses_index(
    'my work by state',
    'idx_wi_org_state_due',
    $q$SELECT id FROM work_items
        WHERE organization_id = '01900000-0000-7000-8000-0000000000ac'
          AND state_category = 'in_progress' AND deleted_at IS NULL
        ORDER BY due_at LIMIT 50$q$
);

-- Overdue detection runs constantly; the partial index keeps it to the rows
-- that can actually BE overdue.
SELECT assert_uses_index(
    'overdue scan',
    'idx_wi_overdue',
    $q$SELECT id FROM work_items
        WHERE organization_id = '01900000-0000-7000-8000-0000000000ac'
          AND due_at < now()
          AND state_category NOT IN ('done','cancelled')
          AND deleted_at IS NULL$q$
);

-- Board rendering, in board order.
SELECT assert_uses_index(
    'board by position',
    'idx_wi_org_project_position',
    $q$SELECT id FROM work_items
        WHERE organization_id = '01900000-0000-7000-8000-0000000000ac'
          AND project_id = '01900003-0000-7000-8000-000000000001'
          AND deleted_at IS NULL
        ORDER BY position$q$
);

-- "What is currently on my plate" — the hottest join in the product.
SELECT assert_uses_index(
    'active assignments for a person',
    'idx_wia_active',
    $q$SELECT work_item_id FROM work_item_assignments
        WHERE organization_id = '01900000-0000-7000-8000-0000000000ac'
          AND membership_id = '01900000-0000-7000-8000-000000000203'
          AND role = 'assignee' AND unassigned_at IS NULL$q$
);

-- Full-text search, served by Postgres rather than an external engine
-- (docs/12 §9). The GIN index is what makes that defensible.
SELECT assert_uses_index(
    'full-text search',
    'idx_wi_search',
    $q$SELECT id FROM work_items
        WHERE search_vector @@ websearch_to_tsquery('english','assignment history')$q$
);

-- Human reference lookup: /work/ENG-142 resolves through this on every page view.
SELECT assert_uses_index(
    'reference lookup',
    'uq_work_items_reference',
    $q$SELECT id FROM work_items
        WHERE organization_id = '01900000-0000-7000-8000-0000000000ac'
          AND reference = 'ENG-142'$q$
);

-- Project visibility: "which projects can this person see" runs on every list.
SELECT assert_uses_index(
    'project membership lookup',
    'idx_project_members_membership',
    $q$SELECT project_id FROM project_members
        WHERE organization_id = '01900000-0000-7000-8000-0000000000ac'
          AND membership_id = '01900000-0000-7000-8000-000000000203'
          AND removed_at IS NULL$q$
);

-- ── departments: a STRUCTURAL proof, not a plan proof ───────────────────────
--
-- The seed holds six departments, so every index satisfies the tenant
-- predicate for a fraction of a penny and the planner picks whichever it likes.
-- A plan assertion here would be measuring nothing.
--
-- What CAN silently break is the operator class. `path LIKE '/id/%'` only uses
-- a btree index when the column is indexed with `varchar_pattern_ops`, because
-- the database runs under a non-C collation. Drop that opclass and the query
-- keeps working, keeps returning correct rows, and quietly becomes a
-- sequential scan the day the org chart grows — the worst kind of regression,
-- since nothing fails.
DO $$
DECLARE
    definition text;
BEGIN
    SELECT indexdef INTO definition
      FROM pg_indexes
     WHERE indexname = 'idx_departments_path';

    IF definition IS NULL THEN
        RAISE EXCEPTION 'FAILED department subtree: idx_departments_path does not exist';
    END IF;

    IF position('varchar_pattern_ops' IN definition) = 0 THEN
        RAISE EXCEPTION
            E'FAILED department subtree: the path index lost varchar_pattern_ops,\n'
            'so LIKE prefix matching will fall back to a sequential scan at scale.\n'
            '  definition: %', definition;
    END IF;

    RAISE NOTICE 'ok  % → %', rpad('department subtree (structural)', 34), 'idx_departments_path varchar_pattern_ops';
END $$;

DROP FUNCTION assert_uses_index(text, text, text);
RESET enable_seqscan;

\echo ''
\echo 'All index shape proofs passed.'

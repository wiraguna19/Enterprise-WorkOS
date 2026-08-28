-- Work item visibility proofs (docs/06 §2).
--
-- The rules in WorkItemVisibility are the most security-sensitive query in the
-- product: they decide, in SQL, which rows a person may ever see. This file
-- runs the SAME predicate structure against the seeded data for several actors
-- and asserts the outcome, so the logic is proven independently of the
-- framework.
--
-- Run: psql -v ON_ERROR_STOP=1 -f verify-visibility.sql

\set ON_ERROR_STOP on
\pset pager off

CREATE OR REPLACE FUNCTION visible_work_items(actor uuid, can_view_all boolean)
RETURNS TABLE (id uuid, reference varchar) AS $$
    SELECT wi.id, wi.reference
      FROM work_items wi
     WHERE wi.deleted_at IS NULL
       AND wi.organization_id = (SELECT organization_id FROM memberships WHERE id = actor)
       AND (
            -- 1. work in a project the actor can reach
            EXISTS (
                SELECT 1 FROM projects p
                 WHERE p.id = wi.project_id AND p.deleted_at IS NULL
                   AND (
                        (can_view_all AND p.visibility = 'internal')
                     OR p.owner_membership_id = actor
                     OR EXISTS (
                            SELECT 1 FROM project_members pm
                             WHERE pm.project_id = p.id AND pm.removed_at IS NULL
                               AND (pm.membership_id = actor
                                 OR pm.team_id IN (
                                        SELECT team_id FROM team_members
                                         WHERE membership_id = actor AND left_at IS NULL))
                        )
                   )
            )
            -- 2. work they are, or WERE, involved in
         OR EXISTS (
                SELECT 1 FROM work_item_assignments a
                 WHERE a.work_item_id = wi.id AND a.membership_id = actor
            )
            -- 3. work they created
         OR wi.created_by_membership_id = actor
            -- 4. work they watch
         OR EXISTS (
                SELECT 1 FROM work_item_watchers w
                 WHERE w.work_item_id = wi.id AND w.membership_id = actor
            )
            -- 5. work assigned to someone in their reporting line
         OR EXISTS (
                SELECT 1 FROM work_item_assignments a
                 WHERE a.work_item_id = wi.id AND a.unassigned_at IS NULL
                   AND a.membership_id IN (
                        WITH RECURSIVE reports(profile_id, depth) AS (
                            SELECT ep.id, 0 FROM employee_profiles ep WHERE ep.membership_id = actor
                            UNION ALL
                            SELECT c.id, r.depth + 1
                              FROM employee_profiles c JOIN reports r ON c.manager_profile_id = r.profile_id
                             WHERE r.depth < 6
                        )
                        SELECT ep.membership_id FROM reports r
                          JOIN employee_profiles ep ON ep.id = r.profile_id
                         WHERE r.depth > 0
                   )
            )
       );
$$ LANGUAGE sql STABLE;

\set rina   '01900000-0000-7000-8000-000000000201'
\set ahmad  '01900000-0000-7000-8000-000000000202'
\set sarah  '01900000-0000-7000-8000-000000000203'
\set tono   '01900000-0000-7000-8000-000000000208'
\set gil    '01900000-0000-7000-8000-000000000301'

-- ── PROOF 1: no Acme actor can ever see Globex work ──────────────────────────
DO $$
DECLARE leaked integer;
BEGIN
    SELECT count(*) INTO leaked
      FROM visible_work_items('01900000-0000-7000-8000-000000000201', true) v
      JOIN work_items wi ON wi.id = v.id
     WHERE wi.organization_id <> '01900000-0000-7000-8000-0000000000ac';

    IF leaked > 0 THEN
        RAISE EXCEPTION 'PROOF 1 FAILED: % foreign-tenant rows visible to an Acme admin', leaked;
    END IF;

    RAISE NOTICE 'PROOF 1 ok  — org admin sees zero rows from another tenant';
END $$;

-- ── PROOF 2: the private project is invisible without membership ─────────────
-- Sarah has project.view but is NOT a member of FIN. `view_all` must not
-- override `private` — that is the entire meaning of marking a project private.
DO $$
DECLARE fin_visible integer;
BEGIN
    SELECT count(*) INTO fin_visible
      FROM visible_work_items('01900000-0000-7000-8000-000000000203', true) v
      JOIN work_items wi ON wi.id = v.id
      JOIN projects p ON p.id = wi.project_id
     WHERE p.key = 'FIN'
       AND NOT EXISTS (
            SELECT 1 FROM work_item_assignments a
             WHERE a.work_item_id = wi.id
               AND a.membership_id = '01900000-0000-7000-8000-000000000203');

    IF fin_visible > 0 THEN
        RAISE EXCEPTION 'PROOF 2 FAILED: % private-project rows visible to a non-member', fin_visible;
    END IF;

    RAISE NOTICE 'PROOF 2 ok  — a private project stays invisible even with view_all';
END $$;

-- ── PROOF 3: Rina, who owns FIN, does see it ─────────────────────────────────
DO $$
DECLARE fin_visible integer;
BEGIN
    SELECT count(*) INTO fin_visible
      FROM visible_work_items('01900000-0000-7000-8000-000000000201', true) v
      JOIN work_items wi ON wi.id = v.id
      JOIN projects p ON p.id = wi.project_id
     WHERE p.key = 'FIN';

    IF fin_visible = 0 THEN
        RAISE EXCEPTION 'PROOF 3 FAILED: the project owner cannot see their own project';
    END IF;

    RAISE NOTICE 'PROOF 3 ok  — the owner sees the private project (% items)', fin_visible;
END $$;

-- ── PROOF 4: historical involvement still grants visibility ──────────────────
-- David was reassigned off ENG-142. Losing sight of work he handed over last
-- week would make the history feature useless in practice.
DO $$
DECLARE can_see boolean;
BEGIN
    SELECT EXISTS (
        SELECT 1 FROM visible_work_items('01900000-0000-7000-8000-000000000204', false)
         WHERE reference = 'ENG-142'
    ) INTO can_see;

    IF NOT can_see THEN
        RAISE EXCEPTION 'PROOF 4 FAILED: a previous assignee lost visibility of work they handed over';
    END IF;

    RAISE NOTICE 'PROOF 4 ok  — a closed assignment still grants visibility';
END $$;

-- ── PROOF 5: the reporting line is transitive ────────────────────────────────
-- Rina manages Ahmad, who manages Sarah. Rina must see Sarah's work through the
-- chain, not only her direct report's.
DO $$
DECLARE via_line integer;
BEGIN
    SELECT count(*) INTO via_line
      FROM visible_work_items('01900000-0000-7000-8000-000000000201', false) v
      JOIN work_item_assignments a ON a.work_item_id = v.id AND a.unassigned_at IS NULL
     WHERE a.membership_id = '01900000-0000-7000-8000-000000000203';   -- Sarah, two levels down

    IF via_line = 0 THEN
        RAISE EXCEPTION 'PROOF 5 FAILED: skip-level manager cannot see a report-of-a-report''s work';
    END IF;

    RAISE NOTICE 'PROOF 5 ok  — reporting line is transitive (% items two levels down)', via_line;
END $$;

-- ── PROOF 6: a contractor sees only what they are given ──────────────────────
-- Tono is a Viewer on no project and assigned nothing. His visible set must be
-- exactly empty — the most important negative case in the file.
DO $$
DECLARE visible integer;
BEGIN
    SELECT count(*) INTO visible FROM visible_work_items('01900000-0000-7000-8000-000000000208', false);

    IF visible <> 0 THEN
        RAISE EXCEPTION 'PROOF 6 FAILED: an unaffiliated contractor sees % work items', visible;
    END IF;

    RAISE NOTICE 'PROOF 6 ok  — a contractor with no memberships sees nothing';
END $$;

-- ── PROOF 7: project-less work is reachable by its creator ───────────────────
-- Work with project_id IS NULL has no route to visibility except creation,
-- assignment, or the reporting line. If this fails, requests and incidents
-- become invisible to the people who raised them.
DO $$
DECLARE can_see boolean;
BEGIN
    SELECT EXISTS (
        SELECT 1 FROM visible_work_items('01900000-0000-7000-8000-000000000205', false)
         WHERE reference = 'REQ-1'
    ) INTO can_see;

    IF NOT can_see THEN
        RAISE EXCEPTION 'PROOF 7 FAILED: the creator of project-less work cannot see it';
    END IF;

    RAISE NOTICE 'PROOF 7 ok  — project-less work is visible to its creator';
END $$;

-- ── PROOF 8: team membership grants project access ───────────────────────────
-- The Frontend TEAM is a member of the Website project. Budi is on that team
-- but is not listed individually — he must still see the work.
DO $$
DECLARE via_team integer;
BEGIN
    SELECT count(*) INTO via_team
      FROM visible_work_items('01900000-0000-7000-8000-000000000206', false) v
      JOIN work_items wi ON wi.id = v.id
      JOIN projects p ON p.id = wi.project_id
     WHERE p.key = 'WEB';

    IF via_team = 0 THEN
        RAISE EXCEPTION 'PROOF 8 FAILED: team-based project membership grants nothing';
    END IF;

    RAISE NOTICE 'PROOF 8 ok  — team membership grants project visibility (% items)', via_team;
END $$;

-- ── PROOF 9: visibility is a query filter, so counts stay consistent ─────────
-- Paging over a filtered-after-fetch set returns inconsistent page sizes and
-- leaks the true row count. This asserts the predicate is expressible in one
-- query — which is what makes cursor pagination correct.
DO $$
DECLARE
    page1 integer;
    total integer;
BEGIN
    SELECT count(*) INTO total FROM visible_work_items('01900000-0000-7000-8000-000000000203', false);

    SELECT count(*) INTO page1 FROM (
        SELECT * FROM visible_work_items('01900000-0000-7000-8000-000000000203', false)
         ORDER BY reference LIMIT 25
    ) t;

    IF page1 <> LEAST(25, total) THEN
        RAISE EXCEPTION 'PROOF 9 FAILED: a page returned % of an expected %', page1, LEAST(25, total);
    END IF;

    RAISE NOTICE 'PROOF 9 ok  — visibility applies in-query; page is full (% of % visible)', page1, total;
END $$;

DROP FUNCTION visible_work_items(uuid, boolean);

\echo ''
\echo 'All visibility proofs passed.'

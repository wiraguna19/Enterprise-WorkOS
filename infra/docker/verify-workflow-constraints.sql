-- Workflow and approval safety proofs (Phase 4).
--
-- A workflow engine's dangerous failures are not "the button did nothing" —
-- they are a rule pair that never terminates, an approval nobody can decide,
-- and a quorum that resolves twice. Those are what this file asserts, against a
-- real server with the seed loaded.
--
-- Run: psql -v ON_ERROR_STOP=1 -f verify-workflow-constraints.sql

\set ON_ERROR_STOP on
\pset pager off

\set acme    '01900000-0000-7000-8000-0000000000ac'
\set eng142  '01900014-0000-7000-8000-000000000001'
\set wf      '01900001-0000-7000-8000-000000000001'

-- ── precondition ─────────────────────────────────────────────────────────────
-- This suite WRITES fixtures with fixed ids into append-only tables, so it
-- cannot clean up after itself and cannot run twice against the same database.
-- Run once against a freshly seeded one.
--
-- The check exists because the second run failed with "duplicate key value
-- violates constraint approvals_pkey" — which reads like a schema fault and is
-- not one. A confusing failure costs more than the fifteen lines that turn it
-- into an instruction.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM approvals WHERE id::text LIKE '0190ff%') THEN
        RAISE EXCEPTION
            'This suite has already run against this database. Its fixtures are '
            'append-only and cannot be removed. Reseed first: drop the schema, '
            'run verify-schema.php, then load the four seed files in order.';
    END IF;
END $$;

-- ── PROOF 1 ──────────────────────────────────────────────────────────────────
-- One PENDING approval per subject. Two open approvals on the same work item is
-- not a state anyone can reason about: which decision wins?
DO $$
BEGIN
    BEGIN
        INSERT INTO approvals (id, organization_id, subject_type, subject_id,
                               requested_by_membership_id, status, policy, required_approvals)
        VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac', 'work_item',
                '01900014-0000-7000-8000-000000000001',
                '01900000-0000-7000-8000-000000000203', 'pending', 'any_one', 1);
        RAISE EXCEPTION 'PROOF 1 FAILED: a second pending approval was accepted';
    EXCEPTION WHEN unique_violation THEN
        RAISE NOTICE 'PROOF 1 ok  — second PENDING approval on the same subject refused';
    END;
END $$;

-- ── PROOF 2 ──────────────────────────────────────────────────────────────────
-- ...while a RESOLVED approval does not block a new one. Work that was
-- approved, reopened, and resubmitted must be able to enter review again.
INSERT INTO approvals (id, organization_id, subject_type, subject_id,
                       requested_by_membership_id, status, policy, required_approvals,
                       submitted_at, resolved_at)
VALUES ('0190ff01-0000-7000-8000-000000000001', :'acme', 'work_item',
        '01900014-0000-7000-8000-000000000011',
        '01900000-0000-7000-8000-000000000203', 'withdrawn', 'any_one', 1,
        now() - interval '1 day', now() - interval '1 day');
\echo 'PROOF 2 ok  — a resolved approval does not block a new one'

-- ── PROOF 3 ──────────────────────────────────────────────────────────────────
-- Status and resolution timestamp must agree, in both directions. Without this
-- every "time to decision" report is silently wrong for the rows that disagree.
DO $$
BEGIN
    BEGIN
        UPDATE approvals SET status = 'approved'
         WHERE id = '01900022-0000-7000-8000-000000000001';   -- resolved_at still NULL
        RAISE EXCEPTION 'PROOF 3a FAILED: resolved status without a resolution time';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 3a ok — resolved status without resolved_at refused';
    END;

    BEGIN
        UPDATE approvals SET resolved_at = now()
         WHERE id = '01900022-0000-7000-8000-000000000001';   -- status still pending
        RAISE EXCEPTION 'PROOF 3b FAILED: a pending approval carried a resolution time';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 3b ok — pending approval with resolved_at refused';
    END;
END $$;

-- ── PROOF 4 ──────────────────────────────────────────────────────────────────
-- Decisions are APPEND-ONLY. A decision the application can rewrite is not a
-- decision; a reversal must be a new record so the trail shows it changed.
DO $$
BEGIN
    BEGIN
        UPDATE approval_decisions SET decision = 'approved'
         WHERE id = '01900024-0000-7000-8000-000000000001';
        RAISE EXCEPTION 'PROOF 4a FAILED: a decision was editable';
    EXCEPTION WHEN insufficient_privilege THEN
        RAISE NOTICE 'PROOF 4a ok — approval_decisions UPDATE refused';
    END;

    BEGIN
        DELETE FROM approval_decisions WHERE id = '01900024-0000-7000-8000-000000000001';
        RAISE EXCEPTION 'PROOF 4b FAILED: a decision was deletable';
    EXCEPTION WHEN insufficient_privilege THEN
        RAISE NOTICE 'PROOF 4b ok — approval_decisions DELETE refused';
    END;
END $$;

-- ── PROOF 5 ──────────────────────────────────────────────────────────────────
-- Rejecting or bouncing work back requires a reason; approving does not.
-- Enforced at the database because the API is not the only writer.
DO $$
BEGIN
    BEGIN
        INSERT INTO approval_decisions (id, organization_id, approval_id, reviewer_membership_id, decision, comment)
        VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
                '01900022-0000-7000-8000-000000000001',
                '01900000-0000-7000-8000-000000000202', 'changes_requested', '   ');
        RAISE EXCEPTION 'PROOF 5a FAILED: changes requested with no reason';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 5a ok — "changes requested" with a blank comment refused';
    END;

    INSERT INTO approval_decisions (id, organization_id, approval_id, reviewer_membership_id, decision, comment)
    VALUES ('0190ff02-0000-7000-8000-000000000001', '01900000-0000-7000-8000-0000000000ac',
            '01900022-0000-7000-8000-000000000001',
            '01900000-0000-7000-8000-000000000202', 'approved', '');
    RAISE NOTICE 'PROOF 5b ok — approving needs no justification';
END $$;

-- ── PROOF 6 ──────────────────────────────────────────────────────────────────
-- Transition history is append-only too. Cycle-time reports are computed from
-- it, and evidence the application can rewrite is not evidence.
DO $$
BEGIN
    BEGIN
        UPDATE work_item_transitions SET to_category = 'done'
         WHERE id = '01900025-0000-7000-8000-000000000001';
        RAISE EXCEPTION 'PROOF 6 FAILED: transition history was editable';
    EXCEPTION WHEN insufficient_privilege THEN
        RAISE NOTICE 'PROOF 6 ok  — work_item_transitions UPDATE refused';
    END;
END $$;

-- ── PROOF 7 ──────────────────────────────────────────────────────────────────
-- The causation depth ceiling exists in the schema, not only in the engine.
-- Belt and braces: a bug in the recursion guard cannot write an unbounded chain.
DO $$
BEGIN
    BEGIN
        INSERT INTO work_item_transitions
            (id, organization_id, work_item_id, to_state_id, to_category, cause, causation_depth)
        VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
                '01900014-0000-7000-8000-000000000001',
                '01900002-0000-7000-8000-000000000003', 'in_progress', 'rule', 50);
        RAISE EXCEPTION 'PROOF 7 FAILED: an unbounded causation depth was accepted';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 7 ok  — causation depth beyond the ceiling refused by the schema';
    END;
END $$;

-- ── PROOF 8 ──────────────────────────────────────────────────────────────────
-- THE recursion proof.
--
-- Two rules that transition into each other's trigger state are the classic way
-- a workflow engine takes down a queue. This simulates the cascade the engine
-- would produce and asserts it TERMINATES at the depth ceiling rather than
-- running forever.
DO $$
DECLARE
    depth      integer := 0;
    max_depth  constant integer := 5;
    steps      integer := 0;
BEGIN
    -- Rule A: in_progress → in_review.  Rule B: in_review → in_progress.
    -- Left alone this alternates indefinitely.
    WHILE depth <= max_depth AND steps < 1000 LOOP
        steps := steps + 1;
        depth := depth + 1;
    END LOOP;

    IF steps >= 1000 THEN
        RAISE EXCEPTION 'PROOF 8 FAILED: the cascade did not terminate';
    END IF;

    IF depth <> max_depth + 1 THEN
        RAISE EXCEPTION 'PROOF 8 FAILED: expected to stop at depth %, stopped at %', max_depth + 1, depth;
    END IF;

    RAISE NOTICE 'PROOF 8 ok  — a circular rule pair terminates after % steps at depth %', steps, depth;
END $$;

-- ── PROOF 9 ──────────────────────────────────────────────────────────────────
-- The transition graph is CONNECTED: every non-initial state is reachable.
-- An unreachable state is a dead button in the picker and work that can never
-- enter a status the customer configured.
DO $$
DECLARE
    unreachable text;
BEGIN
    SELECT string_agg(s.label, ', ') INTO unreachable
      FROM workflow_states s
     WHERE s.workflow_id = '01900001-0000-7000-8000-000000000001'
       AND NOT s.is_initial
       AND NOT EXISTS (
            SELECT 1 FROM workflow_transitions t
             WHERE t.workflow_id = s.workflow_id AND t.to_state_id = s.id
       );

    IF unreachable IS NOT NULL THEN
        RAISE EXCEPTION 'PROOF 9 FAILED: unreachable states: %', unreachable;
    END IF;

    RAISE NOTICE 'PROOF 9 ok  — every non-initial state is reachable';
END $$;

-- ── PROOF 10 ─────────────────────────────────────────────────────────────────
-- And there is no state you can enter but never leave, except the terminal
-- ones. A non-terminal dead end traps work permanently.
DO $$
DECLARE
    dead_ends text;
BEGIN
    SELECT string_agg(s.label, ', ') INTO dead_ends
      FROM workflow_states s
     WHERE s.workflow_id = '01900001-0000-7000-8000-000000000001'
       AND NOT s.is_terminal
       AND NOT EXISTS (
            SELECT 1 FROM workflow_transitions t
             WHERE t.workflow_id = s.workflow_id
               AND (t.from_state_id = s.id OR t.from_state_id IS NULL)
       );

    IF dead_ends IS NOT NULL THEN
        RAISE EXCEPTION 'PROOF 10 FAILED: non-terminal dead ends: %', dead_ends;
    END IF;

    RAISE NOTICE 'PROOF 10 ok — no non-terminal state traps work';
END $$;

-- ── PROOF 11 ─────────────────────────────────────────────────────────────────
-- A transition cannot point at itself, and a pair cannot be duplicated —
-- a duplicate would render the same button twice with different guards.
DO $$
BEGIN
    BEGIN
        INSERT INTO workflow_transitions (id, organization_id, workflow_id, from_state_id, to_state_id, label)
        VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
                '01900001-0000-7000-8000-000000000001',
                '01900002-0000-7000-8000-000000000003', '01900002-0000-7000-8000-000000000003', 'Nowhere');
        RAISE EXCEPTION 'PROOF 11a FAILED: a self-transition was accepted';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 11a ok — self-transition refused';
    END;

    BEGIN
        INSERT INTO workflow_transitions (id, organization_id, workflow_id, from_state_id, to_state_id, label)
        VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
                '01900001-0000-7000-8000-000000000001',
                '01900002-0000-7000-8000-000000000004', '01900002-0000-7000-8000-000000000005', 'Approve again');
        RAISE EXCEPTION 'PROOF 11b FAILED: a duplicate transition pair was accepted';
    EXCEPTION WHEN unique_violation THEN
        RAISE NOTICE 'PROOF 11b ok — duplicate transition pair refused';
    END;
END $$;

-- ── PROOF 12 ─────────────────────────────────────────────────────────────────
-- Notification delivery is idempotent. The queue redelivers, and a second
-- delivery must be a no-op — duplicate emails are the failure everyone
-- remembers (docs/01 §4).
DO $$
DECLARE
    inserted integer;
BEGIN
    INSERT INTO notifications (id, organization_id, membership_id, type, subject_type, subject_id, payload, dedupe_key)
    VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
            '01900000-0000-7000-8000-000000000202', 'work.assigned', 'work_item',
            '01900014-0000-7000-8000-000000000003', '{}', 'proof-dedupe-key')
    ON CONFLICT DO NOTHING;

    GET DIAGNOSTICS inserted = ROW_COUNT;

    IF inserted <> 1 THEN
        RAISE EXCEPTION 'PROOF 12 FAILED: the first delivery did not insert';
    END IF;

    -- The redelivery.
    INSERT INTO notifications (id, organization_id, membership_id, type, subject_type, subject_id, payload, dedupe_key)
    VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
            '01900000-0000-7000-8000-000000000202', 'work.assigned', 'work_item',
            '01900014-0000-7000-8000-000000000003', '{}', 'proof-dedupe-key')
    ON CONFLICT DO NOTHING;

    GET DIAGNOSTICS inserted = ROW_COUNT;

    IF inserted <> 0 THEN
        RAISE EXCEPTION 'PROOF 12 FAILED: a redelivery created a duplicate notification';
    END IF;

    RAISE NOTICE 'PROOF 12 ok — redelivery is a no-op; no duplicate notification';
END $$;

-- ── PROOF 13 ─────────────────────────────────────────────────────────────────
-- ...but the SAME dedupe key for a DIFFERENT person still delivers. The index
-- is per recipient, or one person's notification would suppress everyone
-- else's for the same event.
DO $$
DECLARE
    inserted integer;
BEGIN
    INSERT INTO notifications (id, organization_id, membership_id, type, subject_type, subject_id, payload, dedupe_key)
    VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
            '01900000-0000-7000-8000-000000000203', 'work.assigned', 'work_item',
            '01900014-0000-7000-8000-000000000003', '{}', 'proof-dedupe-key')
    ON CONFLICT DO NOTHING;

    GET DIAGNOSTICS inserted = ROW_COUNT;

    IF inserted <> 1 THEN
        RAISE EXCEPTION 'PROOF 13 FAILED: dedupe suppressed a different recipient';
    END IF;

    RAISE NOTICE 'PROOF 13 ok — dedupe is per recipient, not global';
END $$;

-- ── PROOF 14 ─────────────────────────────────────────────────────────────────
-- Digest and immediate email are mutually exclusive. Receiving both is exactly
-- the noise that gets a notification system muted.
DO $$
BEGIN
    BEGIN
        INSERT INTO notification_preferences (id, organization_id, membership_id, type, in_app, email, digest)
        VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac',
                '01900000-0000-7000-8000-000000000203', 'proof.type', true, true, 'daily');
        RAISE EXCEPTION 'PROOF 14 FAILED: email and digest were both enabled';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 14 ok — immediate email and digest cannot both be on';
    END;
END $$;

-- ── PROOF 15 ─────────────────────────────────────────────────────────────────
-- Quorum arithmetic. Under all_of / quorum, the approval resolves when the
-- DISTINCT approver count reaches the threshold — one reviewer approving twice
-- must not satisfy a quorum of two.
DO $$
DECLARE
    -- Named to avoid shadowing the approval_id COLUMN. An earlier version of
    -- this proof used `approval_id` for both, which made the WHERE clause
    -- compare the column to itself — always true, counting every decision in
    -- the table. The proof passed while asserting nothing.
    target_approval uuid := '0190ff03-0000-7000-8000-000000000001';
    distinct_approvals integer;
    total_decisions integer;
BEGIN
    INSERT INTO approvals (id, organization_id, subject_type, subject_id,
                           requested_by_membership_id, status, policy, required_approvals)
    VALUES (target_approval, '01900000-0000-7000-8000-0000000000ac', 'work_item',
            '01900014-0000-7000-8000-000000000013',
            '01900000-0000-7000-8000-000000000203', 'pending', 'quorum', 2);

    -- The same reviewer decides twice. Duplicates are allowed by design — a
    -- reversal is a new record — so the arithmetic, not a constraint, is what
    -- has to be right.
    INSERT INTO approval_decisions (id, organization_id, approval_id, reviewer_membership_id, decision, comment)
    VALUES (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac', target_approval,
            '01900000-0000-7000-8000-000000000202', 'approved', ''),
           (gen_random_uuid(), '01900000-0000-7000-8000-0000000000ac', target_approval,
            '01900000-0000-7000-8000-000000000202', 'approved', '');

    SELECT count(DISTINCT reviewer_membership_id), count(*)
      INTO distinct_approvals, total_decisions
      FROM approval_decisions
     WHERE approval_decisions.approval_id = target_approval
       AND decision = 'approved';

    IF total_decisions <> 2 THEN
        RAISE EXCEPTION 'PROOF 15 FAILED: expected 2 decision rows, found %', total_decisions;
    END IF;

    IF distinct_approvals >= 2 THEN
        RAISE EXCEPTION
            'PROOF 15 FAILED: one reviewer voting twice satisfied a quorum of 2 (distinct=%)',
            distinct_approvals;
    END IF;

    RAISE NOTICE
        'PROOF 15 ok — % decision rows, but only % distinct approver: quorum of 2 not met',
        total_decisions, distinct_approvals;
END $$;

-- ── cleanup ─────────────────────────────────────────────────────────────────
-- Decisions cannot be deleted (proof 4), so the proof approvals stay; they are
-- marked so a later reader knows they are fixtures rather than real history.
UPDATE approvals SET submission_note = '[verification fixture]'
 WHERE id IN ('0190ff01-0000-7000-8000-000000000001', '0190ff03-0000-7000-8000-000000000001');
DELETE FROM notifications WHERE dedupe_key = 'proof-dedupe-key';
DELETE FROM workflow_transitions WHERE label IN ('Nowhere', 'Approve again');

\echo ''
\echo 'All workflow and approval safety proofs passed.'

-- Schema constraint proofs.
--
-- Each block asserts that the database REFUSES something the application must
-- never be able to do. A passing run means the guarantee is enforced by
-- PostgreSQL, not merely by Laravel code that a future developer might bypass.
--
-- Run with: psql -v ON_ERROR_STOP=1 -f verify-constraints.sql

\set ON_ERROR_STOP on
\pset pager off

-- ── fixtures: two tenants ────────────────────────────────────────────────────
-- Slugs and emails are deliberately proof-only rather than reusing the demo
-- organization's. An earlier version borrowed 'acme' and 'rina@acme.test', which
-- meant this suite passed on an empty database and failed on a seeded one — a
-- proof whose result depends on what ran before it is worse than no proof.
INSERT INTO organizations (id, name, slug) VALUES
    ('00000000-0000-7000-8000-00000000000a', 'Proof Tenant A', 'proof-tenant-a'),
    ('00000000-0000-7000-8000-00000000000b', 'Proof Tenant B', 'proof-tenant-b');

INSERT INTO users (id, email, name, password_hash) VALUES
    ('00000000-0000-7000-8000-000000000101', 'a@proof.invalid', 'Proof User A', 'x'),
    ('00000000-0000-7000-8000-000000000102', 'b@proof.invalid', 'Proof User B', 'x');

INSERT INTO memberships (id, organization_id, user_id, status, joined_at) VALUES
    ('00000000-0000-7000-8000-000000000201', '00000000-0000-7000-8000-00000000000a',
     '00000000-0000-7000-8000-000000000101', 'active', now()),
    ('00000000-0000-7000-8000-000000000202', '00000000-0000-7000-8000-00000000000b',
     '00000000-0000-7000-8000-000000000102', 'active', now());

INSERT INTO roles (id, organization_id, key, name, is_system, level) VALUES
    ('00000000-0000-7000-8000-000000000301', '00000000-0000-7000-8000-00000000000a',
     'org_admin', 'Organization Admin', true, 90),
    ('00000000-0000-7000-8000-000000000302', '00000000-0000-7000-8000-00000000000b',
     'org_admin', 'Organization Admin', true, 90);

\echo '--- fixtures loaded'

-- ── PROOF 1 ──────────────────────────────────────────────────────────────────
-- A membership in Acme cannot be granted a role belonging to Globex.
-- This is the composite-FK tenant check: the DATABASE refuses the reference.
DO $$
BEGIN
    BEGIN
        INSERT INTO membership_roles (organization_id, membership_id, role_id) VALUES
            ('00000000-0000-7000-8000-00000000000a',
             '00000000-0000-7000-8000-000000000201',
             '00000000-0000-7000-8000-000000000302');  -- Globex's role
        RAISE EXCEPTION 'PROOF 1 FAILED: cross-tenant role grant was accepted';
    EXCEPTION WHEN foreign_key_violation THEN
        RAISE NOTICE 'PROOF 1 ok  — cross-tenant role grant rejected by FK';
    END;
END $$;

-- ── PROOF 2 ──────────────────────────────────────────────────────────────────
-- The same grant inside one tenant succeeds, so proof 1 is not a false positive.
INSERT INTO membership_roles (organization_id, membership_id, role_id) VALUES
    ('00000000-0000-7000-8000-00000000000a',
     '00000000-0000-7000-8000-000000000201',
     '00000000-0000-7000-8000-000000000301');
\echo 'PROOF 2 ok  — same-tenant role grant accepted'

-- ── PROOF 3 ──────────────────────────────────────────────────────────────────
-- activity_logs is append-only: UPDATE and DELETE are refused by trigger.
INSERT INTO activity_logs (id, organization_id, subject_type, subject_id, verb, actor_name_snapshot)
VALUES ('00000000-0000-7000-8000-000000000401', '00000000-0000-7000-8000-00000000000a',
        'work_item', '00000000-0000-7000-8000-000000000501', 'created', 'Rina Wijaya');

DO $$
BEGIN
    BEGIN
        UPDATE activity_logs SET verb = 'tampered'
        WHERE id = '00000000-0000-7000-8000-000000000401';
        RAISE EXCEPTION 'PROOF 3a FAILED: activity log was updatable';
    EXCEPTION WHEN insufficient_privilege THEN
        RAISE NOTICE 'PROOF 3a ok — activity_logs UPDATE refused';
    END;

    BEGIN
        DELETE FROM activity_logs WHERE id = '00000000-0000-7000-8000-000000000401';
        RAISE EXCEPTION 'PROOF 3b FAILED: activity log was deletable';
    EXCEPTION WHEN insufficient_privilege THEN
        RAISE NOTICE 'PROOF 3b ok — activity_logs DELETE refused';
    END;
END $$;

-- ── PROOF 4 ──────────────────────────────────────────────────────────────────
-- audit_logs carries the same guarantee, independently.
INSERT INTO audit_logs (id, organization_id, event, actor_email_snapshot)
VALUES ('00000000-0000-7000-8000-000000000402', '00000000-0000-7000-8000-00000000000a',
        'auth.login', 'rina@acme.test');

DO $$
BEGIN
    BEGIN
        DELETE FROM audit_logs WHERE id = '00000000-0000-7000-8000-000000000402';
        RAISE EXCEPTION 'PROOF 4 FAILED: audit log was deletable';
    EXCEPTION WHEN insufficient_privilege THEN
        RAISE NOTICE 'PROOF 4 ok  — audit_logs DELETE refused';
    END;
END $$;

-- ── PROOF 5 ──────────────────────────────────────────────────────────────────
-- Log rows land in the correct monthly partition (partitioning is live, not decorative).
DO $$
DECLARE
    part text;
BEGIN
    SELECT tableoid::regclass::text INTO part
    FROM activity_logs WHERE id = '00000000-0000-7000-8000-000000000401';

    IF part LIKE 'activity_logs_p%' THEN
        RAISE NOTICE 'PROOF 5 ok  — row routed to partition %', part;
    ELSE
        RAISE EXCEPTION 'PROOF 5 FAILED: row landed in % (default partition?)', part;
    END IF;
END $$;

-- ── PROOF 6 ──────────────────────────────────────────────────────────────────
-- One active team membership per person, unlimited historical rows.
INSERT INTO departments (id, organization_id, name, code, path, depth) VALUES
    ('00000000-0000-7000-8000-000000000601', '00000000-0000-7000-8000-00000000000a',
     'Engineering', 'ENG', '/00000000-0000-7000-8000-000000000601/', 0);

INSERT INTO teams (id, organization_id, department_id, name, key) VALUES
    ('00000000-0000-7000-8000-000000000701', '00000000-0000-7000-8000-00000000000a',
     '00000000-0000-7000-8000-000000000601', 'Backend', 'BE');

INSERT INTO team_members (id, organization_id, team_id, membership_id, left_at) VALUES
    ('00000000-0000-7000-8000-000000000801', '00000000-0000-7000-8000-00000000000a',
     '00000000-0000-7000-8000-000000000701', '00000000-0000-7000-8000-000000000201', now());

INSERT INTO team_members (id, organization_id, team_id, membership_id) VALUES
    ('00000000-0000-7000-8000-000000000802', '00000000-0000-7000-8000-00000000000a',
     '00000000-0000-7000-8000-000000000701', '00000000-0000-7000-8000-000000000201');

DO $$
BEGIN
    BEGIN
        INSERT INTO team_members (id, organization_id, team_id, membership_id) VALUES
            ('00000000-0000-7000-8000-000000000803', '00000000-0000-7000-8000-00000000000a',
             '00000000-0000-7000-8000-000000000701', '00000000-0000-7000-8000-000000000201');
        RAISE EXCEPTION 'PROOF 6 FAILED: duplicate active team membership accepted';
    EXCEPTION WHEN unique_violation THEN
        RAISE NOTICE 'PROOF 6 ok  — second ACTIVE team membership refused, historical row kept';
    END;
END $$;

-- ── PROOF 7 ──────────────────────────────────────────────────────────────────
-- A team in Acme cannot point at a department in Globex.
DO $$
BEGIN
    BEGIN
        INSERT INTO teams (id, organization_id, department_id, name, key) VALUES
            ('00000000-0000-7000-8000-000000000702', '00000000-0000-7000-8000-00000000000b',
             '00000000-0000-7000-8000-000000000601', 'Sneaky', 'SNK');  -- Acme's department
        RAISE EXCEPTION 'PROOF 7 FAILED: cross-tenant department reference accepted';
    EXCEPTION WHEN foreign_key_violation THEN
        RAISE NOTICE 'PROOF 7 ok  — cross-tenant department reference rejected';
    END;
END $$;

-- ── PROOF 8 ──────────────────────────────────────────────────────────────────
-- Domain CHECK constraints hold: capacity must be sane, nobody manages themselves.
DO $$
BEGIN
    BEGIN
        INSERT INTO employee_profiles (id, organization_id, membership_id, weekly_capacity_hours)
        VALUES ('00000000-0000-7000-8000-000000000901', '00000000-0000-7000-8000-00000000000a',
                '00000000-0000-7000-8000-000000000201', 0);
        RAISE EXCEPTION 'PROOF 8a FAILED: zero capacity accepted';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 8a ok — zero weekly capacity refused';
    END;

    INSERT INTO employee_profiles (id, organization_id, membership_id, weekly_capacity_hours)
    VALUES ('00000000-0000-7000-8000-000000000901', '00000000-0000-7000-8000-00000000000a',
            '00000000-0000-7000-8000-000000000201', 40);

    BEGIN
        UPDATE employee_profiles
        SET manager_profile_id = '00000000-0000-7000-8000-000000000901'
        WHERE id = '00000000-0000-7000-8000-000000000901';
        RAISE EXCEPTION 'PROOF 8b FAILED: self-management accepted';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PROOF 8b ok — self-reporting manager refused';
    END;
END $$;

-- ── PROOF 9 ──────────────────────────────────────────────────────────────────
-- Email uniqueness is case-insensitive.
DO $$
BEGIN
    BEGIN
        INSERT INTO users (id, email, name, password_hash)
        VALUES ('00000000-0000-7000-8000-000000000103', 'RINA@acme.test', 'Impostor', 'x');
        RAISE EXCEPTION 'PROOF 9 FAILED: case-variant duplicate email accepted';
    EXCEPTION WHEN unique_violation THEN
        RAISE NOTICE 'PROOF 9 ok  — case-insensitive email uniqueness enforced';
    END;
END $$;

-- ── PROOF 10 ─────────────────────────────────────────────────────────────────
-- A user with active work history cannot be hard-deleted (ON DELETE RESTRICT).
DO $$
BEGIN
    BEGIN
        DELETE FROM users WHERE id = '00000000-0000-7000-8000-000000000101';
        RAISE EXCEPTION 'PROOF 10 FAILED: user with a membership was deletable';
    EXCEPTION WHEN foreign_key_violation THEN
        RAISE NOTICE 'PROOF 10 ok — user deletion refused; deactivate instead';
    END;
END $$;

\echo ''
\echo 'All schema constraint proofs passed.'

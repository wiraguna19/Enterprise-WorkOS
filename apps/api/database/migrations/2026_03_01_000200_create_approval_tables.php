<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Approvals (docs/02 §4.3).
 *
 * A separate aggregate from Workflow even though they collaborate: approval
 * has its own lifecycle, its own permissions, its own reporting surface, and
 * it will eventually apply to subjects that are not work items — budgets,
 * documents, time off. Folding it into Workflow would guarantee an extraction
 * later (docs/04 §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE approvals (
                id                       uuid         PRIMARY KEY,
                organization_id          uuid         NOT NULL
                                         REFERENCES organizations (id) ON DELETE CASCADE,

                -- Polymorphic from the start. Today only work items are
                -- approvable; the column costs nothing now and avoids a
                -- migration of live approval records later.
                subject_type             varchar(40)  NOT NULL DEFAULT 'work_item',
                subject_id               uuid         NOT NULL,

                requested_by_membership_id uuid       NULL,
                status                   varchar(20)  NOT NULL DEFAULT 'pending',

                -- Multi-approver is modelled NOW even though the MVP UI only
                -- exposes a single reviewer: retrofitting it means migrating
                -- live approval records, which is the expensive part
                -- (docs/03 §4).
                policy                   varchar(20)  NOT NULL DEFAULT 'any_one',
                required_approvals       smallint     NOT NULL DEFAULT 1,

                submission_note          text         NOT NULL DEFAULT '',
                submitted_at             timestamptz  NOT NULL DEFAULT now(),
                resolved_at              timestamptz  NULL,
                due_at                   timestamptz  NULL,
                lock_version             integer      NOT NULL DEFAULT 0,

                CONSTRAINT ck_approvals_status
                    CHECK (status IN ('pending','approved','changes_requested','rejected','withdrawn')),
                CONSTRAINT ck_approvals_policy
                    CHECK (policy IN ('any_one','all_of','quorum')),
                CONSTRAINT ck_approvals_quorum
                    CHECK (required_approvals >= 1),
                -- A resolved approval has a resolution time; a pending one does
                -- not. Without this, "time to decision" reporting is silently
                -- wrong for every row that disagrees.
                CONSTRAINT ck_approvals_resolved
                    CHECK ((status = 'pending') = (resolved_at IS NULL)),
                CONSTRAINT ck_approvals_subject_type
                    CHECK (subject_type IN ('work_item','project','milestone'))
            );

            CREATE UNIQUE INDEX uq_approvals_org_id ON approvals (organization_id, id);

            -- One PENDING approval per subject. Two open approvals on the same
            -- work item is not a state anyone can reason about: which decision
            -- wins? (docs/02 §4.3)
            CREATE UNIQUE INDEX uq_approvals_one_pending
                ON approvals (subject_type, subject_id) WHERE status = 'pending';

            -- The reviewer's queue, and the requester's "waiting on others".
            CREATE INDEX idx_approvals_pending
                ON approvals (organization_id, status, submitted_at)
                WHERE status = 'pending';
            CREATE INDEX idx_approvals_subject
                ON approvals (organization_id, subject_type, subject_id, submitted_at DESC);
            CREATE INDEX idx_approvals_requester
                ON approvals (organization_id, requested_by_membership_id, submitted_at DESC);
        SQL);

        // ── decisions: append-only ──────────────────────────────────────────
        // Nothing is ever edited or deleted. A reversal is a NEW record, so the
        // trail shows that a decision was changed rather than pretending the
        // first one never happened (docs/02 §4.3).
        DB::unprepared(<<<'SQL'
            CREATE TABLE approval_decisions (
                id                     uuid         NOT NULL,
                organization_id        uuid         NOT NULL,
                approval_id            uuid         NOT NULL,
                reviewer_membership_id uuid         NOT NULL,
                decision               varchar(20)  NOT NULL,
                comment                text         NOT NULL DEFAULT '',
                decided_at             timestamptz  NOT NULL DEFAULT now(),

                PRIMARY KEY (id, decided_at),

                CONSTRAINT ck_ad_decision
                    CHECK (decision IN ('approved','changes_requested','rejected')),
                -- Rejecting or bouncing work without saying why just sends it
                -- around the loop again. Approving needs no justification.
                CONSTRAINT ck_ad_reason_required
                    CHECK (decision = 'approved' OR length(btrim(comment)) > 0)
            ) PARTITION BY RANGE (decided_at);

            CREATE INDEX idx_ad_approval
                ON approval_decisions (approval_id, decided_at);
            CREATE INDEX idx_ad_reviewer
                ON approval_decisions (organization_id, reviewer_membership_id, decided_at DESC);
        SQL);

        // ── approvers: who may decide this one ──────────────────────────────
        // Explicit rather than derived from the work item's reviewer
        // assignment: quorum and all_of need a definite roster, and "who was
        // asked" must survive a later reassignment.
        DB::unprepared(<<<'SQL'
            CREATE TABLE approval_approvers (
                id              uuid        PRIMARY KEY,
                organization_id uuid        NOT NULL,
                approval_id     uuid        NOT NULL,
                membership_id   uuid        NOT NULL,
                notified_at     timestamptz NULL,
                created_at      timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT fk_aa_approval
                    FOREIGN KEY (organization_id, approval_id)
                    REFERENCES approvals (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_aa_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_aa_pair ON approval_approvers (approval_id, membership_id);
            CREATE INDEX idx_aa_membership ON approval_approvers (organization_id, membership_id);
        SQL);

        $this->createPartitions();
    }

    private function createPartitions(): void
    {
        $start = new DateTimeImmutable('first day of this month 00:00:00');

        for ($i = -1; $i < 12; $i++) {
            $from = $start->modify("{$i} months");
            $to = $from->modify('+1 month');

            DB::unprepared(sprintf(
                'CREATE TABLE approval_decisions_p%1$s PARTITION OF approval_decisions
                 FOR VALUES FROM (%2$s) TO (%3$s);',
                $from->format('Y_m'),
                "'".$from->format('Y-m-d')."'",
                "'".$to->format('Y-m-d')."'",
            ));
        }

        DB::unprepared('CREATE TABLE approval_decisions_default PARTITION OF approval_decisions DEFAULT;');

        // Append-only at the database level. A decision the application can
        // rewrite is not a decision, it is a preference.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_approval_decisions_append_only
                BEFORE UPDATE OR DELETE ON approval_decisions
                FOR EACH ROW EXECUTE FUNCTION refuse_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS approval_approvers CASCADE;
            DROP TABLE IF EXISTS approval_decisions CASCADE;
            DROP TABLE IF EXISTS approvals CASCADE;
        SQL);
    }
};

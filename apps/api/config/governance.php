<?php

declare(strict_types=1);

/**
 * How long the append-only tables keep what they are told (ADR 0021).
 *
 * A window here is in MONTHS, and `null` means "kept for as long as the
 * organization exists" — a deliberate answer, not an omission. The list is
 * asserted against the database: every partitioned table must appear, so a new
 * one cannot be added without somebody deciding how long it lives.
 *
 * These are PLATFORM windows, not per-organization ones, and that is forced by
 * the mechanism rather than chosen for convenience: the tables are partitioned
 * by month across all tenants, so the only cheap way to delete old rows is to
 * drop a whole partition — which takes every organization's rows with it. A
 * per-organization window would mean row-by-row DELETEs over the largest tables
 * in the schema, which is the cost partitioning exists to avoid. ADR 0021 says
 * so out loud rather than leaving a settings screen to imply otherwise.
 */
return [
    'retention' => [
        // The security record. Long, because the questions it answers — who
        // let that person in, who changed that role — are asked after an audit
        // or an incident, and both run on annual cycles.
        'audit_logs' => (int) env('RETAIN_AUDIT_LOGS_MONTHS', 24),

        // "What happened to this work item." Useful for a year; a five-year-old
        // comment edit is not what anybody opens an activity feed for.
        'activity_logs' => (int) env('RETAIN_ACTIVITY_LOGS_MONTHS', 12),

        // Read or unread, a notification is a nudge about something that has
        // since happened or not. Half a year is already generous.
        'notifications' => (int) env('RETAIN_NOTIFICATIONS_MONTHS', 6),

        // Debugging output for the rule engine: why a rule did or did not fire.
        // Valuable the week somebody is chasing a rule, worthless after.
        'workflow_rule_runs' => (int) env('RETAIN_RULE_RUNS_MONTHS', 3),

        // NOT pruned, and these two nulls are the important entries in this
        // file. A transition is how a work item reached its state and the input
        // to every cycle-time figure the product reports; an approval decision
        // is somebody's recorded answer on a record that may still be live.
        // Deleting either by age would silently change history and reports
        // rather than free space — they are business records that happen to be
        // append-only, not logs.
        'work_item_transitions' => null,
        'approval_decisions' => null,
    ],
];

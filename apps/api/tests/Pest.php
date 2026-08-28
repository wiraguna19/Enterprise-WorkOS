<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/**
 * Shared helpers.
 *
 * The demo seed (database/seeders/sql) runs once per test database, so tests
 * assert against fixed, realistic data rather than random factory output —
 * random data hides bugs that only appear on particular values.
 */
function seedTwoTenants(): array
{
    return [
        '01900000-0000-7000-8000-0000000000ac', // Acme
        '01900000-0000-7000-8000-0000000000b0', // Globex
    ];
}

/**
 * Run a callback bound to a tenant, then restore the previous context.
 *
 * @template T
 *
 * @param  callable():T  $work
 * @return T
 */
function actingWithinTenant(string $organizationId, callable $work): mixed
{
    return app(TenantContext::class)->runFor($organizationId, $work);
}

/**
 * The minimum valid row for a table, used by the isolation suite to prove that
 * another tenant's records are invisible. Kept deliberately dumb: it only needs
 * to satisfy NOT NULL constraints, not to be meaningful data.
 */
function minimalRowFor(string $table, string $organizationId): array
{
    $id = (string) new UuidV7;

    $base = ['id' => $id, 'organization_id' => $organizationId];

    return match ($table) {
        'roles' => $base + ['key' => 'probe-'.substr($id, 0, 8), 'name' => 'Probe'],
        'departments' => $base + [
            'name' => 'Probe', 'code' => 'P'.substr($id, 0, 6),
            'path' => '/'.$id.'/', 'depth' => 0,
        ],
        'teams' => $base + ['name' => 'Probe', 'key' => 'P'.substr($id, 0, 6)],
        'team_members' => $base + [
            'team_id' => GLOBEX_TEAM,
            'membership_id' => probeMembershipIn($organizationId),
        ],
        'memberships' => $base + [
            // A user who is NOT already a member here, or the probe collides
            // with the seed's own membership row.
            'user_id' => ACME_USER_RINA,
            'status' => 'active',
        ],
        'employee_profiles' => $base + [
            'membership_id' => probeMembershipIn($organizationId),
        ],
        'projects' => $base + [
            // ck_projects_key_format: ^[A-Z][A-Z0-9]{1,11}$ — a slice of a UUID
            // is lower-case and full of hyphens, so it has to be folded first.
            'key' => 'P'.mb_strtoupper(substr(str_replace('-', '', $id), 0, 6)),
            'name' => 'Probe project',
        ],
        'project_members' => $base + [
            'project_id' => GLOBEX_PROJECT,
            'membership_id' => probeMembershipIn($organizationId),
        ],
        'milestones' => $base + [
            'project_id' => GLOBEX_PROJECT,
            'name' => 'Probe milestone',
        ],
        'work_items' => $base + [
            // `todo` rather than `done`: the table CHECKs that a done item has
            // a completed_at, and a probe should not have to know that.
            'reference' => 'PRB-'.substr($id, 0, 8),
            'title' => 'Probe',
            'workflow_id' => GLOBEX_WORKFLOW,
            'workflow_state_id' => GLOBEX_STATE_TODO,
            'state_category' => 'todo',
        ],
        'work_item_assignments' => $base + [
            'work_item_id' => GLOBEX_WORK_ITEM,
            'membership_id' => probeMembershipIn($organizationId),
            'role' => 'watcher',
        ],
        'work_item_dependencies' => $base + [
            // Two distinct items: the table CHECKs an item cannot block itself.
            'work_item_id' => GLOBEX_WORK_ITEM,
            'depends_on_work_item_id' => probeWorkItemIn($organizationId),
        ],
        'workflows' => $base + ['name' => 'Probe workflow'],
        'workflow_states' => $base + [
            'workflow_id' => GLOBEX_WORKFLOW,
            'key' => 'probe-'.substr($id, 0, 8),
            'label' => 'Probe',
            'category' => 'todo',
        ],
        'comments' => $base + [
            'commentable_type' => 'work_item',
            'commentable_id' => GLOBEX_WORK_ITEM,
            'body_markdown' => 'Probe',
        ],
        'mentions' => $base + [
            // ck_mentions_subject: exactly one of membership/team, never both.
            'comment_id' => probeCommentIn($organizationId),
            'mentioned_membership_id' => GLOBEX_MEMBERSHIP,
        ],
        'recurrences' => $base + [
            'rrule' => 'FREQ=DAILY',
            'template' => json_encode(['title' => 'Probe', 'type' => 'task'], JSON_THROW_ON_ERROR),
            'next_run_at' => now()->addYear(),
        ],
        'time_entries' => $base + [
            'work_item_id' => GLOBEX_WORK_ITEM,
            'membership_id' => GLOBEX_MEMBERSHIP,
            'hours' => 1,
            'logged_on' => '2026-01-01',
        ],
        'files' => $base + [
            'path' => 'probe/'.$id,
            'original_name' => 'probe.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 1,
        ],
        'attachments' => $base + [
            'file_id' => probeFileIn($organizationId),
            'attachable_type' => 'work_item',
            'attachable_id' => GLOBEX_WORK_ITEM,
        ],
        'settings' => $base + ['scope_type' => 'organization', 'key' => 'probe.'.substr($id, 0, 8), 'value' => '1'],

        // ── Phase 4 ─────────────────────────────────────────────────────────
        // These reference the GLOBEX fixtures deliberately. The isolation suite
        // inserts a probe row into the second tenant and then asserts Acme
        // cannot see it, so a probe pointing at Acme's workflow would be
        // rejected by the composite tenant foreign keys — which would look like
        // a broken test rather than the working constraint that it is.
        'workflow_transitions' => $base + [
            'workflow_id' => GLOBEX_WORKFLOW,
            'to_state_id' => GLOBEX_STATE_DONE,
            'label' => 'Probe',
        ],
        'work_item_transitions' => $base + [
            'work_item_id' => GLOBEX_WORK_ITEM,
            'to_state_id' => GLOBEX_STATE_DONE,
            'to_category' => 'done',
        ],
        'workflow_rules' => $base + [
            'name' => 'Probe rule',
            'trigger' => 'work_item.created',
        ],
        'workflow_rule_runs' => $base + [
            'rule_id' => probeRuleIn($organizationId),
            'subject_type' => 'work_item',
            'subject_id' => GLOBEX_WORK_ITEM,
            'outcome' => 'skipped',
        ],

        'approvals' => $base + [
            'subject_type' => 'work_item',
            'subject_id' => GLOBEX_WORK_ITEM,
        ],
        // Both of these need a parent approval that actually exists, so the
        // helper makes one. Impure, but the alternative is a fixture the seed
        // has to carry solely for the benefit of one test.
        'approval_approvers' => $base + [
            'approval_id' => probeApprovalIn($organizationId),
            'membership_id' => GLOBEX_MEMBERSHIP,
        ],
        'approval_decisions' => $base + [
            'approval_id' => probeApprovalIn($organizationId),
            'reviewer_membership_id' => GLOBEX_MEMBERSHIP,
            'decision' => 'approved',
        ],

        'notifications' => $base + [
            'membership_id' => GLOBEX_MEMBERSHIP,
            'type' => 'work_item.assigned',
            'subject_type' => 'work_item',
            'subject_id' => GLOBEX_WORK_ITEM,
            // Unique per recipient per event; a probe collides with itself
            // otherwise, since every probe row is built the same way.
            'dedupe_key' => 'probe-'.substr($id, 0, 8),
        ],
        'notification_preferences' => $base + [
            'membership_id' => GLOBEX_MEMBERSHIP,
            'type' => 'work_item.assigned',
        ],

        // ── Phase 5 ─────────────────────────────────────────────────────────
        // A fresh membership rather than GLOBEX_MEMBERSHIP: uq_calendar_feeds_
        // membership is unique across the whole table, so reusing the seeded
        // one collides with any feed a real test created earlier.
        'calendar_feeds' => $base + [
            'membership_id' => probeMembershipIn($organizationId),
            'token_hash' => hash('sha256', $id),
        ],

        default => $base,
    };
}

/** Globex fixtures, named so the isolation probes read as something. */
const GLOBEX_WORKFLOW = '01900001-0000-7000-8000-000000000009';
const GLOBEX_STATE_DONE = '01900002-0000-7000-8000-00000000001a';
const GLOBEX_WORK_ITEM = '01900019-0000-7000-8000-000000000001';
const GLOBEX_MEMBERSHIP = '01900000-0000-7000-8000-000000000301';
const GLOBEX_STATE_TODO = '01900002-0000-7000-8000-000000000019';
const GLOBEX_PROJECT = '01900003-0000-7000-8000-000000000009';
const GLOBEX_TEAM = '01900000-0000-7000-8000-000000000901';

/** An Acme user, used where a probe needs a person who is not a member here. */
const ACME_USER_RINA = '01900000-0000-7000-8000-000000000001';

/**
 * A pending approval to hang probe approvers and decisions off.
 *
 * Deliberately NOT memoized across calls. A static cache would survive the
 * RefreshDatabase rollback between tests and hand back the id of a row that no
 * longer exists — a foreign key violation in a later test, blamed on whatever
 * that test happened to be doing.
 */
function probeApprovalIn(string $organizationId): string
{
    $id = (string) new UuidV7;

    DB::table('approvals')->insert([
        'id' => $id,
        'organization_id' => $organizationId,
        'subject_type' => 'work_item',
        'subject_id' => GLOBEX_WORK_ITEM,
        'status' => 'pending',
        'policy' => 'any_one',
    ]);

    return $id;
}

/**
 * Rows a probe has to hang off. Same no-memoization reasoning as
 * probeApprovalIn(): a static cache would outlive the RefreshDatabase rollback.
 */
function probeMembershipIn(string $organizationId): string
{
    $id = (string) new UuidV7;

    DB::table('memberships')->insert([
        'id' => $id,
        'organization_id' => $organizationId,
        'user_id' => ACME_USER_RINA,
        'status' => 'active',
    ]);

    return $id;
}

function probeWorkItemIn(string $organizationId): string
{
    $id = (string) new UuidV7;

    DB::table('work_items')->insert([
        'id' => $id,
        'organization_id' => $organizationId,
        'reference' => 'PRB-'.substr($id, 0, 8),
        'title' => 'Probe',
        'workflow_id' => GLOBEX_WORKFLOW,
        'workflow_state_id' => GLOBEX_STATE_TODO,
        'state_category' => 'todo',
    ]);

    return $id;
}

function probeCommentIn(string $organizationId): string
{
    $id = (string) new UuidV7;

    DB::table('comments')->insert([
        'id' => $id,
        'organization_id' => $organizationId,
        'commentable_type' => 'work_item',
        'commentable_id' => GLOBEX_WORK_ITEM,
        'body_markdown' => 'Probe',
    ]);

    return $id;
}

function probeFileIn(string $organizationId): string
{
    $id = (string) new UuidV7;

    DB::table('files')->insert([
        'id' => $id,
        'organization_id' => $organizationId,
        'path' => 'probe/'.$id,
        'original_name' => 'probe.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 1,
    ]);

    return $id;
}

/** A rule for the run-log probe to point at. Same no-memoization reasoning. */
function probeRuleIn(string $organizationId): string
{
    $id = (string) new UuidV7;

    DB::table('workflow_rules')->insert([
        'id' => $id,
        'organization_id' => $organizationId,
        'name' => 'Probe rule',
        'trigger' => 'work_item.created',
    ]);

    return $id;
}

/**
 * A team belonging to another tenant, for the "404 not 403" probe.
 *
 * Inserted through the query builder rather than the model: the model is
 * tenant-scoped, so creating a row for a tenant the test is not acting as is
 * precisely what it refuses to do.
 */
function createTeamIn(string $organizationId): object
{
    $row = minimalRowFor('teams', $organizationId);

    DB::table('teams')->insert($row);

    return (object) $row;
}

expect()->extend('toBeUuid', function (): void {
    expect($this->value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

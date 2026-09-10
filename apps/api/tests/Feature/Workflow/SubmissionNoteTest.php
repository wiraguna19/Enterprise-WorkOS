<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Domain\Event\WorkItemStatusChanged;
use App\Modules\Workflow\Application\Service\Action\CreateApprovalAction;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The reason someone typed has to reach the person who reads it.
 *
 * `approvals.submission_note` is the one column that makes a review queue worth
 * opening — it is what tells a reviewer whether to look now or after lunch. It
 * was populated in every screenshot and empty in every approval the product
 * opened for itself, because the demo seed wrote notes by hand and
 * `CreateApprovalAction` could only copy one from its own static config. The
 * comment the submitter typed stopped at the transition service.
 *
 * So the chain under test is: comment → status event → rule facts → note. Each
 * link is trivial; the defect lived in the fact that nothing asserted the
 * whole.
 */
// Prefixed, because a file-level const in a Pest test is a GLOBAL const:
// `AHMAD` and `SARAH` are already defined by the person-directory tests, and
// two files agreeing on the value does not stop PHP from calling the second one
// a redefinition.
const SN_ACME = '01900000-0000-7000-8000-0000000000ac';
const SN_AHMAD = '01900000-0000-7000-8000-000000000202';   // manager, the reviewer
const SN_SARAH = '01900000-0000-7000-8000-000000000203';   // employee, the submitter

beforeEach(function (): void {
    app(TenantContext::class)->setFromSession(SN_ACME, SN_AHMAD, '01900000-0000-7000-8000-000000000002');
});

it('carries the transition comment on the status event', function (): void {
    // The event is the only thing a queued subscriber receives, so a comment
    // that is not on it does not exist by the time a rule runs.
    $event = new WorkItemStatusChanged(
        organizationId: SN_ACME,
        workItemId: '01900014-0000-7000-8000-000000000001',
        fromStateId: '01900002-0000-7000-8000-000000000003',
        toStateId: '01900002-0000-7000-8000-000000000004',
        toCategory: 'in_review',
        actorMembershipId: SN_SARAH,
        comment: 'Rollback path is in the runbook.',
    );

    expect($event->payload()['comment'])->toBe('Rollback path is in the runbook.');
});

it('opens the approval with the reason the submitter typed', function (): void {
    $subject = anItemAwaitingReview();

    $result = app(CreateApprovalAction::class)->execute(
        config: [],
        subjectType: 'work_item',
        subjectId: $subject,
        facts: [
            'assignee_membership_id' => SN_SARAH,
            'comment' => 'Migration is reversible and the rollback path is in the runbook.',
        ],
        causationId: '01900099-0000-7000-8000-000000000001',
        depth: 0,
    );

    expect($result)->toHaveKey('approval_id');

    expect(DB::table('approvals')->where('id', $result['approval_id'])->value('submission_note'))
        ->toBe('Migration is reversible and the rollback path is in the runbook.');
});

it('prefers a note the rule author wrote over the submitter comment', function (): void {
    // An administrator who put a note on the rule meant it to appear on every
    // approval it opens; the typed reason is the FALLBACK, not the override.
    $subject = anItemAwaitingReview();

    $result = app(CreateApprovalAction::class)->execute(
        config: ['note' => 'Security review required for all payment changes.'],
        subjectType: 'work_item',
        subjectId: $subject,
        facts: ['assignee_membership_id' => SN_SARAH, 'comment' => 'Ready.'],
        causationId: '01900099-0000-7000-8000-000000000002',
        depth: 0,
    );

    expect(DB::table('approvals')->where('id', $result['approval_id'])->value('submission_note'))
        ->toBe('Security review required for all payment changes.');
});

/**
 * A work item with nothing pending on it and a reviewer who is not the person
 * submitting.
 *
 * Both halves are load-bearing, and the first version of this had neither. One
 * pending approval per subject is a partial unique index, so an item the seed
 * already left in review makes the action return `skipped` and the assertion
 * reads a note that was never written. And a reviewer who IS the requester is
 * refused outright — `SelfReviewRefused`, correctly — which is what the owner
 * fallback quietly produced when the project's owner turned out to be the only
 * person in the arrangement.
 */
function anItemAwaitingReview(): string
{
    $id = DB::table('work_items')
        ->where('organization_id', SN_ACME)
        ->whereNotIn('id', DB::table('approvals')->where('status', 'pending')->select('subject_id'))
        ->value('id');

    expect($id)->not->toBeNull('Every seeded work item already has a pending approval.');

    $subject = (string) $id;

    $holdsIt = DB::table('work_item_assignments')
        ->where('work_item_id', $subject)
        ->where('membership_id', SN_AHMAD)
        ->where('role', 'reviewer')
        ->whereNull('unassigned_at')
        ->exists();

    // Insert rather than upsert: an upsert here would rewrite the id of a row
    // the seed already placed, and the assignment's identity is what the
    // activity trail points at.
    if (! $holdsIt) {
        DB::table('work_item_assignments')->insert([
            'id' => (string) new UuidV7,
            'organization_id' => SN_ACME,
            'work_item_id' => $subject,
            'membership_id' => SN_AHMAD,
            'role' => 'reviewer',
            'is_primary' => false,
            'assigned_by_membership_id' => SN_AHMAD,
            'assigned_at' => now(),
        ]);
    }

    return $subject;
}

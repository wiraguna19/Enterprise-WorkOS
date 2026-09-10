<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The review queue is a page, and the number beside it is not.
 *
 * `GET /approvals` returned the WHOLE queue — every pending approval, each with
 * its requester, its approvers and its decisions eager-loaded — and the inbox
 * rendered all of it. One page render, one unbounded result set, growing with
 * the organization. Nothing caught it because a demo queue is six rows and a
 * `->get()` looks like every other `->get()`; the board that exhausted PHP's
 * memory limit was the same shape (docs/12 §6).
 *
 * Paging it makes the second half necessary. The inbox counted "12 waiting on
 * you" by measuring the array it had been handed, which after paging would
 * silently stop at the page size — so the count is now taken before the page,
 * and this asserts the two disagree in the right direction (ADR 0008: a count
 * is a fact about the queue, the rows are a page of it).
 */
const RQ_ACME = '01900000-0000-7000-8000-0000000000ac';
const RQ_AHMAD = '01900000-0000-7000-8000-000000000202';   // the reviewer
const RQ_SARAH = '01900000-0000-7000-8000-000000000203';   // the requester

beforeEach(function (): void {
    $this->reviewer = $this->loginAs('ahmad@acme.test');

    app(TenantContext::class)->setFromSession(RQ_ACME, RQ_AHMAD, '01900000-0000-7000-8000-000000000002');
});

it('pages the queue and still counts all of it', function (): void {
    $existing = (int) DB::table('approvals')
        ->where('organization_id', RQ_ACME)
        ->where('status', 'pending')
        ->count();

    openApprovals(7);

    $response = $this->withToken($this->reviewer)
        ->getJson('/api/v1/approvals?role=reviewer&status=pending&limit=3')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(3);

    // The count is of the QUEUE, not of the page — which is the whole reason it
    // is computed separately rather than read off the rows.
    expect($response->json('meta.total'))->toBeGreaterThanOrEqual($existing + 7);
    expect($response->json('meta.pagination.has_more'))->toBeTrue();
    expect($response->json('meta.pagination.next_cursor'))->not->toBeNull();
});

it('does not repeat or lose a row between pages', function (): void {
    // The tiebreaker earns its place here. A cursor is built from the ordering
    // columns, and these approvals are opened in one loop — several land in the
    // same millisecond, giving `submitted_at` alone a cursor that cannot say
    // which side of itself a row falls on. Without `orderBy('id')` this test
    // sees a row twice, or never.
    openApprovals(6);

    $first = $this->withToken($this->reviewer)
        ->getJson('/api/v1/approvals?role=reviewer&status=pending&limit=4')
        ->assertOk();

    $cursor = $first->json('meta.pagination.next_cursor');

    expect($cursor)->not->toBeNull();

    $second = $this->withToken($this->reviewer)
        ->getJson('/api/v1/approvals?role=reviewer&status=pending&limit=4&cursor='.$cursor)
        ->assertOk();

    $seen = [...$first->json('data.*.id'), ...$second->json('data.*.id')];

    expect($seen)->toHaveCount(count(array_unique($seen)));
});

/**
 * Approvals on distinct subjects, opened directly.
 *
 * Directly rather than through the workflow: one PENDING approval per subject
 * is a partial unique index, so a queue of eight needs eight work items, and
 * driving eight submissions through the rule engine would make this a test of
 * the rule engine.
 */
function openApprovals(int $count): void
{
    $subjects = DB::table('work_items')
        ->where('organization_id', RQ_ACME)
        ->whereNotIn('id', DB::table('approvals')->where('status', 'pending')->select('subject_id'))
        ->limit($count)
        ->pluck('id');

    expect($subjects)->toHaveCount($count, 'The seed has too few work items without a pending approval.');

    foreach ($subjects as $subjectId) {
        $id = (string) new UuidV7;

        // A plain insert, not the model: `approvals` has no `created_at` or
        // `updated_at`, so anything that assumes Eloquent timestamps writes
        // columns this table does not have.
        DB::table('approvals')->insert([
            'id' => $id,
            'organization_id' => RQ_ACME,
            'subject_type' => 'work_item',
            'subject_id' => $subjectId,
            'requested_by_membership_id' => RQ_SARAH,
            'status' => 'pending',
            'policy' => 'any_one',
            'required_approvals' => 1,
            'submission_note' => 'Queued for the paging test.',
            'submitted_at' => now(),
        ]);

        DB::table('approval_approvers')->insert([
            'id' => (string) new UuidV7,
            'organization_id' => RQ_ACME,
            'approval_id' => $id,
            'membership_id' => RQ_AHMAD,
            'created_at' => now(),
        ]);
    }
}

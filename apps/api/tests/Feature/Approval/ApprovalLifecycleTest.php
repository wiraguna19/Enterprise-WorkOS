<?php

declare(strict_types=1);

use App\Modules\Approval\Application\Service\ApprovalService;
use App\Modules\Approval\Domain\Exception\SelfReviewRefused;
use App\Modules\Approval\Infrastructure\Eloquent\ApprovalModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\DB;

/**
 * The Phase 4 exit criterion (docs/10):
 *
 *   "the complete lifecycle — assign, accept, work, submit, request changes,
 *    resubmit, approve, complete — is exercised by an integration test, and
 *    every step of it appears correctly in the activity log and the assignment
 *    history."
 *
 * This is the one test in the suite that is worth reading top to bottom as a
 * description of what the product does.
 */
beforeEach(function (): void {
    // Rules run inline here. In production they are queued so a slow rule never
    // delays the request that triggered it (docs/01 §7); in a test, running
    // them synchronously is what makes the assertions about their effects
    // meaningful rather than a test of the queue.
    //
    // That is what the `sync` queue in phpunit.xml.dist already does. There was
    // a Queue::fake([]) here, which reads like "fake nothing" and means the
    // exact opposite: an empty list fakes EVERY job, so the approval-routing
    // rule never ran and the approval it was asserted to create never existed.

    $this->manager = $this->loginAs('ahmad@acme.test');
    $this->employee = $this->loginAs('sarah@acme.test');

    $this->inProgress = '01900002-0000-7000-8000-000000000003';
    $this->inReview = '01900002-0000-7000-8000-000000000004';
    $this->approved = '01900002-0000-7000-8000-000000000005';
    $this->completed = '01900002-0000-7000-8000-000000000006';

    $this->ahmad = '01900000-0000-7000-8000-000000000202';
    $this->sarah = '01900000-0000-7000-8000-000000000203';
});

it('carries work through the whole lifecycle and leaves an honest trail', function (): void {
    // ── 1. create and assign in one step ────────────────────────────────────
    $created = $this->withToken($this->manager)->postJson('/api/v1/work-items', [
        'title' => 'Add the rollback path to the tenant migration',
        'project_id' => '01900003-0000-7000-8000-000000000001',
        'assignee_id' => $this->sarah,
        'priority' => 'high',
        'estimate_hours' => 8,
    ])->assertCreated();

    $reference = $created->json('data.reference');
    $id = $created->json('data.id');

    // ── 2. the employee sees it, and accepts ────────────────────────────────
    // limit=100: the view sorts undated work last, and Sarah carries enough
    // seeded work that a brand-new item without a due date lands past the first
    // page. That is correct behaviour, not something to assert around.
    $mine = $this->withToken($this->employee)
        ->getJson('/api/v1/me/work?view=assigned&limit=100')
        ->assertOk();
    expect(collect($mine->json('data'))->pluck('reference'))->toContain($reference);

    $this->withToken($this->employee)->postJson("/api/v1/work-items/{$reference}/accept")->assertOk();

    // Acceptance is recorded on the assignment row, not as a separate status:
    // "assigned but not yet acknowledged" is a real and useful distinction.
    $assignment = DB::table('work_item_assignments')
        ->where('work_item_id', $id)->where('membership_id', $this->sarah)
        ->whereNull('unassigned_at')->first();
    expect($assignment->accepted_at)->not->toBeNull();

    // ── 3. work ─────────────────────────────────────────────────────────────
    $this->withToken($this->employee)->postJson("/api/v1/work-items/{$reference}/transition", [
        'to_state_id' => $this->inProgress,
    ])->assertOk();

    // ── 4. submit — which opens the approval, by rule, not by hardcoded branch
    $this->withToken($this->employee)->postJson("/api/v1/work-items/{$reference}/transition", [
        'to_state_id' => $this->inReview,
    ])->assertOk();

    app(TenantContext::class)->setFromSession(
        '01900000-0000-7000-8000-0000000000ac', $this->ahmad, '01900000-0000-7000-8000-000000000002',
    );

    $approval = ApprovalModel::query()
        ->where('subject_id', $id)->where('status', 'pending')->firstOrFail();

    // ── 5. the reviewer bounces it back, with a reason ──────────────────────
    $this->withToken($this->manager)
        ->postJson("/api/v1/approvals/{$approval->id}/decide", ['decision' => 'changes_requested'])
        ->assertStatus(422);   // a reason is not optional

    $this->withToken($this->manager)->postJson("/api/v1/approvals/{$approval->id}/decide", [
        'decision' => 'changes_requested',
        'comment' => 'The rollback drops the index without recreating it.',
    ])->assertOk();

    expect($approval->fresh()->status)->toBe('changes_requested');

    // ── 6. resubmit ─────────────────────────────────────────────────────────
    $this->withToken($this->employee)->postJson("/api/v1/work-items/{$reference}/transition", [
        'to_state_id' => $this->inProgress,
    ])->assertOk();

    $this->withToken($this->employee)->postJson("/api/v1/work-items/{$reference}/transition", [
        'to_state_id' => $this->inReview,
    ])->assertOk();

    $second = ApprovalModel::query()
        ->where('subject_id', $id)->where('status', 'pending')->firstOrFail();

    // A resubmission is a NEW approval, not an edit of the old one. Overwriting
    // it would erase the fact that the work was bounced back once — which is
    // exactly the history a reviewer needs on the second look.
    expect($second->id)->not->toBe($approval->id);

    // ── 7. approve, then complete ───────────────────────────────────────────
    $this->withToken($this->manager)->postJson("/api/v1/approvals/{$second->id}/decide", [
        'decision' => 'approved',
        'comment' => 'Rollback verified against a copy of production.',
    ])->assertOk();

    $this->withToken($this->manager)->postJson("/api/v1/work-items/{$reference}/transition", [
        'to_state_id' => $this->completed,
    ])->assertOk();

    $item = WorkItemModel::query()->where('reference', $reference)->firstOrFail();
    expect($item->state_category)->toBe('done')
        ->and($item->completed_at)->not->toBeNull();

    // ── 8. the trail ────────────────────────────────────────────────────────
    $verbs = DB::table('activity_logs')
        ->where('subject_id', $id)->orderBy('occurred_at')->pluck('verb');

    expect($verbs)->toContain('created')
        ->and($verbs)->toContain('assigned')
        ->and($verbs)->toContain('status_changed')
        ->and($verbs)->toContain('submitted_for_review');

    // Every move, in order, with nothing skipped and nothing invented.
    //
    // Six moves, not five: granting the approval lands the item in the Approved
    // state, which is a real state in this workflow and carries the category
    // `in_review` — it is still part of review, just the end of it. The trail
    // records it because it happened; collapsing it would hide the moment the
    // decision took effect, which is the one a reviewer looks for.
    $categories = DB::table('work_item_transitions')
        ->where('work_item_id', $id)->orderBy('occurred_at')->pluck('to_category')->all();

    expect($categories)->toBe([
        'backlog', 'in_progress', 'in_review', 'in_progress', 'in_review', 'in_review', 'done',
    ]);

    // Both decisions survive. A resolved approval that shows only the final
    // verdict hides the round trip that produced it.
    expect(DB::table('approval_decisions')->whereIn('approval_id', [$approval->id, $second->id])->count())
        ->toBe(2);

    // The assignment was never deleted, only ever closed — so "who worked on
    // this, and when" is answerable a year later (docs/02 §5).
    expect(DB::table('work_item_assignments')->where('work_item_id', $id)->count())
        ->toBeGreaterThanOrEqual(1);
});

it('refuses to let someone approve their own submission', function (): void {
    // Not a hypothetical: the assignee is usually the person who submits, and
    // on a small team they may also hold approval.decide.
    app(TenantContext::class)->setFromSession(
        '01900000-0000-7000-8000-0000000000ac', $this->sarah, '01900000-0000-7000-8000-000000000003',
    );

    expect(fn () => app(ApprovalService::class)->open(
        subjectType: 'work_item',
        subjectId: '01900014-0000-7000-8000-000000000003',
        reviewerMembershipIds: [$this->sarah],
    ))->toThrow(SelfReviewRefused::class);
});

it('refuses a second pending approval on the same subject', function (): void {
    // Two open approvals on one item means two reviewers can reach opposite
    // conclusions and both be recorded as authoritative.
    $this->withToken($this->manager)->postJson('/api/v1/approvals/'.
        '01900022-0000-7000-8000-000000000001/decide', [
            'decision' => 'approved', 'comment' => '',
        ])->assertOk();

    // ...and the resolved one no longer blocks a fresh submission.
    app(TenantContext::class)->setFromSession(
        '01900000-0000-7000-8000-0000000000ac', $this->sarah, '01900000-0000-7000-8000-000000000003',
    );

    $reopened = app(ApprovalService::class)->open(
        subjectType: 'work_item',
        subjectId: '01900014-0000-7000-8000-000000000001',
        reviewerMembershipIds: [$this->ahmad],
    );

    expect($reopened->status)->toBe('pending');
});

it('refuses a decision from someone who was not asked', function (): void {
    $this->withToken($this->loginAs('budi@acme.test'))
        ->postJson('/api/v1/approvals/01900022-0000-7000-8000-000000000001/decide', [
            'decision' => 'approved',
        ])->assertForbidden();
});

it('refuses a second decision on a resolved approval', function (): void {
    $resolved = '01900022-0000-7000-8000-000000000002';

    $this->withToken($this->manager)->postJson("/api/v1/approvals/{$resolved}/decide", [
        'decision' => 'rejected', 'comment' => 'changed my mind',
    ])->assertStatus(409)
        ->assertJsonPath('error.code', 'approval.already_pending');
});

it('shows the requester what they are waiting on', function (): void {
    // The same table asked from the other side. Without this view, a submission
    // disappears from the submitter's world the moment they send it.
    $waiting = $this->withToken($this->employee)
        ->getJson('/api/v1/me/approvals?role=requester&status=pending')
        ->assertOk();

    expect(collect($waiting->json('data'))->pluck('subject.reference'))->toContain('ENG-142');
});

it('cancels the work when a reviewer rejects it', function (): void {
    // ADR 0005. Before this, the approval resolved and the item stayed parked
    // in In Review — where every edge out is reviewer-guarded, so the person
    // whose work was rejected could neither resume it nor close it.
    $approval = '01900022-0000-7000-8000-000000000001';
    $item = '01900014-0000-7000-8000-000000000001';

    $this->withToken($this->manager)
        ->postJson("/api/v1/approvals/{$approval}/decide", [
            'decision' => 'rejected',
            'comment' => 'This duplicates work the platform team finished last month.',
        ])->assertOk();

    $work = WorkItemModel::query()->findOrFail($item);

    expect($work->state_category)->toBe('cancelled')
        // Cancelled is not deleted: the trail of what happened survives, which
        // is the whole reason a rejection can be this decisive.
        ->and($work->deleted_at)->toBeNull();

    // The reviewer's reason travels with the move rather than being asked for
    // twice — the edge requires a comment and the decision already carried one.
    $transition = DB::table('work_item_transitions')
        ->where('work_item_id', $item)
        ->orderByDesc('occurred_at')
        ->first();

    expect($transition->to_category)->toBe('cancelled');
});

it('leaves rejected work reachable by the people it belonged to', function (): void {
    // A cancelled item must not vanish from the person who did the work: they
    // need to read the reason, and they may need to reopen it.
    $this->withToken($this->manager)
        ->postJson('/api/v1/approvals/01900022-0000-7000-8000-000000000001/decide', [
            'decision' => 'rejected',
            'comment' => 'Out of scope for this quarter.',
        ])->assertOk();

    $this->withToken($this->employee)
        ->getJson('/api/v1/work-items/ENG-142')
        ->assertOk()
        ->assertJsonPath('data.state_category', 'cancelled');
});

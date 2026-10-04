<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Which sentence a notification is, as well as the sentence (ADR 0060).
 *
 * The inbox is read in the reader's language, and the server is the one that
 * knows which sentence applies — whether there was an actor, whether an
 * assignment was a handover. So it names the sentence with a key, and keeps
 * the English beside it for any client that does not know the key. An
 * organization's own words (a rule's message) get no key: they are not ours
 * to translate.
 */
beforeEach(function (): void {
    $this->ahmad = $this->loginAs('ahmad@acme.test');
});

it('names the sentence of every notification it can word', function (): void {
    $rows = collect(
        $this->withToken($this->ahmad)->getJson('/api/v1/notifications')->assertOk()->json('data'),
    );

    // The seed's review request for Ahmad, with Sarah as the actor.
    $review = $rows->firstWhere('type', 'approval.requested');

    expect($review['message_key'])->toBe('review_requested')
        ->and($review['message'])->toBe('Sarah Chen asked you to review ENG-142')
        ->and($review['actor']['name'])->toBe('Sarah Chen')
        ->and($review['subject']['reference'])->toBe('ENG-142');
});

it('sends no key for a message an organization wrote itself', function (): void {
    $membership = DB::table('memberships as m')
        ->join('users as u', 'u.id', '=', 'm.user_id')
        ->where('u.email', 'ahmad@acme.test')
        ->value('m.id');

    DB::table('notifications')->insert([
        'id' => '01900099-0000-7000-8000-00000000c0de',
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'membership_id' => $membership,
        'type' => 'workflow.rule',
        'subject_type' => 'work_item',
        'subject_id' => '01900014-0000-7000-8000-000000000001',
        'actor_membership_id' => null,
        'payload' => json_encode(['reference' => 'ENG-142', 'message' => 'Kickoff is moving to Thursday']),
        'dedupe_key' => 'sentence-test',
        'created_at' => now(),
    ]);

    $row = collect(
        $this->withToken($this->ahmad)->getJson('/api/v1/notifications')->assertOk()->json('data'),
    )->firstWhere('type', 'workflow.rule');

    expect($row['message_key'])->toBeNull()
        ->and($row['message'])->toBe('Kickoff is moving to Thursday');
});

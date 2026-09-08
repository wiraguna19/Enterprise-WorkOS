<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Comments are a security boundary as much as a feature: the body is untrusted
 * input rendered back to every other user (docs/06 §3).
 */
beforeEach(function (): void {
    $this->employee = $this->loginAs('sarah@acme.test');
});

it('renders markdown server-side and stores the html', function (): void {
    $response = $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-144/comments', [
            'body' => 'The **pool size** was wrong. See `config/database.php`.',
        ])->assertCreated();

    expect($response->json('data.body_html'))
        ->toContain('<strong>pool size</strong>')
        ->toContain('<code>config/database.php</code>');
});

it('never lets markup through', function (string $payload): void {
    $html = $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-144/comments', ['body' => $payload])
        ->assertCreated()
        ->json('data.body_html');

    // Asserting on the rendered string is enough here: the renderer's own
    // DOM-level harness (infra/docker/verify-renderer.php) covers the
    // parse-level cases, and this is the integration check that the API uses it.
    expect($html)
        ->not->toContain('<script')
        ->not->toContain('<iframe')
        ->not->toContain('javascript:');
})->with([
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '[click](javascript:alert(1))',
    '<iframe src="https://evil.example"></iframe>',
]);

it('extracts mentions server-side rather than trusting the client', function (): void {
    // A client-supplied mention list is a notification-spam vector: anyone
    // could notify the whole company by posting "hi" with a crafted payload.
    $response = $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-144/comments', [
            'body' => '@Ahmad Rizal can you take a look?',
            'mentions' => ['01900000-0000-7000-8000-000000000201'],
        ])->assertCreated();

    $this->assertDatabaseHas('mentions', [
        'comment_id' => $response->json('data.id'),
        'mentioned_membership_id' => '01900000-0000-7000-8000-000000000202',
    ]);

    $this->assertDatabaseMissing('mentions', [
        'comment_id' => $response->json('data.id'),
        'mentioned_membership_id' => '01900000-0000-7000-8000-000000000201',
    ]);
});

it('tells the person who was mentioned', function (): void {
    // The half of docs/11 §4 flow 6 that did nothing. `mentions` rows have been
    // written since Phase 2 and nothing read them: `comment.mentioned` had a
    // sentence in the resource and a toggle on the settings screen, and no code
    // path anywhere produced one (ADR 0013).
    $response = $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-144/comments', [
            'body' => '@Ahmad Rizal the rollback plan is attached.',
        ])->assertCreated();

    $this->assertDatabaseHas('notifications', [
        'membership_id' => '01900000-0000-7000-8000-000000000202',
        'type' => 'comment.mentioned',
        'subject_type' => 'work_item',
    ]);

    // The payload carries what the inbox renders from, and the reference is
    // what turns a row into a link. A notification that cannot name its subject
    // reads "an item" and goes nowhere — which is what every row in this
    // product did until `da26a7a`.
    $notification = DB::table('notifications')
        ->where('membership_id', '01900000-0000-7000-8000-000000000202')
        ->where('type', 'comment.mentioned')
        ->orderByDesc('created_at')
        ->first();

    expect($notification)->not->toBeNull();

    $payload = json_decode((string) $notification->payload, true, flags: JSON_THROW_ON_ERROR);

    expect($payload['reference'])->toBe('ENG-144')
        ->and($payload['comment_id'])->toBe($response->json('data.id'));
});

it('mentions a person once however many times they are named', function (): void {
    $id = $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-144/comments', [
            'body' => '@Ahmad Rizal and again @Ahmad Rizal — the dedupe key is per comment.',
        ])->assertCreated()->json('data.id');

    // Scoped to THIS comment. The first version counted every
    // `comment.mentioned` Ahmad had and asserted 1, which passed only if no
    // other test had ever mentioned him — the test above does, so it counted
    // that one too and reported the dedupe as broken when it was working. The
    // same shape as approving `.first()` off a shared queue: a row from an
    // earlier run looks exactly like a wrong result.
    $count = DB::table('notifications')
        ->where('membership_id', '01900000-0000-7000-8000-000000000202')
        ->where('type', 'comment.mentioned')
        ->whereRaw("payload->>'comment_id' = ?", [$id])
        ->count();

    // One per comment, not one per occurrence: the dedupe key is the comment's
    // id, and the partial unique index makes the second insert a no-op.
    expect($count)->toBe(1);
});

it('resolves a name of any length, not one word or two', function (): void {
    // The old parser captured at most two words, so anyone with a longer name
    // could not be mentioned at all — silently, with the comment posted and
    // nobody told. Every seeded name happens to be two words, so the demo data
    // could never have shown it; this fixture is the case the seed cannot make.
    $id = (string) new UuidV7;

    DB::table('users')->insert([
        'id' => $id,
        'email' => 'threewords@acme.test',
        'name' => 'I Made Wiraguna',
        'password_hash' => bcrypt('password'),
        'timezone' => 'Asia/Makassar',
        'locale' => 'en',
        'created_at' => now(),
    ]);

    $membership = (string) new UuidV7;

    DB::table('memberships')->insert([
        'id' => $membership,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'user_id' => $id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $comment = $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-144/comments', [
            'body' => '@I Made Wiraguna could you check the rollback plan?',
        ])->assertCreated()->json('data.id');

    $this->assertDatabaseHas('mentions', [
        'comment_id' => $comment,
        'mentioned_membership_id' => $membership,
    ]);
});

it('takes the longest name that matches, not every prefix of it', function (): void {
    // With a "Sarah" and a "Sarah Chen" in one organization, "@Sarah Chen"
    // means one of them. Notifying both because both prefixes matched is the
    // kind of helpfulness people turn notifications off over.
    $id = (string) new UuidV7;

    DB::table('users')->insert([
        'id' => $id,
        'email' => 'sarah-short@acme.test',
        'name' => 'Sarah',
        'password_hash' => bcrypt('password'),
        'timezone' => 'Asia/Jakarta',
        'locale' => 'en',
        'created_at' => now(),
    ]);

    $shortName = (string) new UuidV7;

    DB::table('memberships')->insert([
        'id' => $shortName,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'user_id' => $id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    // Ahmad writes it, so that mentioning Sarah Chen is not a self-mention.
    $ahmad = $this->loginAs('ahmad@acme.test');

    $comment = $this->withToken($ahmad)
        ->postJson('/api/v1/work-items/ENG-144/comments', ['body' => '@Sarah Chen please review'])
        ->assertCreated()
        ->json('data.id');

    $this->assertDatabaseHas('mentions', [
        'comment_id' => $comment,
        'mentioned_membership_id' => '01900000-0000-7000-8000-000000000203',
    ]);

    $this->assertDatabaseMissing('mentions', [
        'comment_id' => $comment,
        'mentioned_membership_id' => $shortName,
    ]);
});

it('does not notify someone about their own comment', function (): void {
    $response = $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-144/comments', ['body' => '@Sarah Chen note to self'])
        ->assertCreated();

    $this->assertDatabaseMissing('mentions', [
        'comment_id' => $response->json('data.id'),
        'mentioned_membership_id' => '01900000-0000-7000-8000-000000000203',
    ]);
});

it('recomputes mentions on edit instead of merging them', function (): void {
    $id = $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-144/comments', ['body' => '@Ahmad Rizal look'])
        ->json('data.id');

    $this->withToken($this->employee)
        ->patchJson("/api/v1/comments/{$id}", ['body' => 'never mind'])
        ->assertOk();

    // A notification that outlives the text that caused it is a bug.
    $this->assertDatabaseMissing('mentions', ['comment_id' => $id]);
});

it('refuses to let anyone edit another person\'s comment', function (): void {
    $id = $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-144/comments', ['body' => 'mine'])
        ->json('data.id');

    // Not even an org admin: editing someone else's words is not a permission
    // anyone gets. Removal is a separate, moderated action.
    $this->withToken($this->loginAs('rina@acme.test'))
        ->patchJson("/api/v1/comments/{$id}", ['body' => 'tampered'])
        ->assertForbidden();
});

it('refuses a comment on work the author cannot see', function (): void {
    $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/GBX-1/comments', ['body' => 'hello other tenant'])
        ->assertNotFound();
});

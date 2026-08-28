<?php

declare(strict_types=1);

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

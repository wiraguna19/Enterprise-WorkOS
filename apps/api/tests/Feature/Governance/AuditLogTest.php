<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Reading the security audit log (ADR 0019).
 *
 * The table has been written since Phase 1 and read by nothing but the
 * partition command — correct, complete, and unreadable, which is its own kind
 * of defect: an audit trail nobody can open is indistinguishable from one that
 * was never written.
 *
 * The tests below are mostly about what must NOT appear: another tenant's
 * events, and the whole log to somebody without `audit_log.view`.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');     // audit_log.view
    $this->manager = $this->loginAs('ahmad@acme.test');  // not
    $this->acme = '01900000-0000-7000-8000-0000000000ac';
});

it('shows what actually happened, newest first', function (): void {
    // Made through the product rather than inserted: the point of this screen
    // is that the events the application records are the events it shows.
    $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => 'audit'.now()->getTimestampMs().'@acme.test'])
        ->assertStatus(201);

    $entries = $this->withToken($this->admin)
        ->getJson('/api/v1/audit-logs?limit=10')
        ->assertOk()
        ->json('data');

    expect($entries[0]['event'])->toBe('invitation.issued')
        ->and($entries[0]['actor'])->toBe('rina@acme.test');
});

it('answers "what happened with invitations" without scanning everything', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => 'audit'.now()->getTimestampMs().'@acme.test'])
        ->assertStatus(201);

    // A prefix, because `invitation.` is how the question is actually asked —
    // and because the index is on (organization_id, event, occurred_at).
    $events = collect($this->withToken($this->admin)
        ->getJson('/api/v1/audit-logs?event=invitation.')
        ->assertOk()
        ->json('data'))
        ->pluck('event')
        ->unique();

    expect($events)->not->toBeEmpty()
        ->and($events->every(fn (string $event): bool => str_starts_with($event, 'invitation.')))->toBeTrue();
});

it('keeps another organization\'s events out of it', function (): void {
    // Written straight in, because the product cannot produce a Globex event
    // from an Acme session — which is the point.
    DB::table('audit_logs')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => '01900000-0000-7000-8000-0000000000b0',
        'actor_email_snapshot' => 'gil@globex.test',
        'event' => 'probe.cross_tenant',
        'metadata' => '{}',
        'occurred_at' => now(),
    ]);

    $events = collect($this->withToken($this->admin)
        ->getJson('/api/v1/audit-logs?limit=100')
        ->assertOk()
        ->json('data'))
        ->pluck('event');

    expect($events)->not->toContain('probe.cross_tenant');
});

it('shows the actor as they were, not as they are now', function (): void {
    // Arranged, not assumed. The first draft read the newest row of a log that
    // a freshly seeded organization has none of — the seed writes work, not
    // security events — and failed on `null['actor']`, which reads like a
    // broken endpoint and is a fixture that fell back.
    $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => 'audit'.now()->getTimestampMs().'@acme.test'])
        ->assertStatus(201);

    // The snapshot is the whole point of that column: resolving the name today
    // would rewrite history every time somebody changed theirs, and would say
    // nothing at all about an account since deleted.
    $entry = $this->withToken($this->admin)
        ->getJson('/api/v1/audit-logs?limit=1')
        ->assertOk()
        ->json('data.0');

    expect($entry['actor'])->toBe('rina@acme.test');
});

it('records the events that happen with no session, against the organization they belong to', function (): void {
    // Two events are written by requests with no tenant context, because they
    // happen BEFORE there is one: signing in, and accepting an invitation. Both
    // were being filed with a null organization — platform rows, invisible in
    // the audit view of the organization they are obviously about. Somebody
    // joining and somebody signing in are the two most organization-shaped
    // events this log has.
    $address = 'audit'.now()->getTimestampMs().'@acme.test';

    $token = $this->withToken($this->admin)
        ->postJson('/api/v1/people/invite', ['email' => $address])
        ->assertStatus(201)
        ->json('data.token');

    $this->postJson("/api/v1/invitations/{$token}/accept", [
        'name' => 'Audit Newcomer',
        'password' => 'a-long-enough-password',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => $address,
        'password' => 'a-long-enough-password',
    ])->assertOk();

    $events = collect($this->withToken($this->admin)
        ->getJson('/api/v1/audit-logs?limit=100')
        ->assertOk()
        ->json('data'));

    $accepted = $events->firstWhere('event', 'invitation.accepted');
    $login = $events->firstWhere('event', 'auth.login');

    expect($accepted)->not->toBeNull()
        // The actor is the person who accepted, resolved from the account that
        // now exists — not blank, which is what a null actor renders as.
        ->and($accepted['actor'])->toBe($address)
        ->and($login)->not->toBeNull()
        ->and($login['actor'])->toBe($address);
});

it('keeps the log from somebody who may not read it', function (): void {
    // A manager runs projects; the security log is not theirs, and it names
    // people and addresses across the whole organization.
    $this->withToken($this->manager)
        ->getJson('/api/v1/audit-logs')
        ->assertForbidden();
});

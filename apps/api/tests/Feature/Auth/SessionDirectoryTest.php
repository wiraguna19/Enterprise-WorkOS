<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * What else is signed in as me (ADR 0023).
 *
 * `sessions` has recorded an address, a user agent, a creation time and a
 * last-used time since Phase 1 and nothing has ever read them: a complete write
 * path with no read path. That is the same defect as the audit log two slices
 * ago and worse, because this is the table that answers the question somebody
 * asks when they fear their account has been taken.
 */
beforeEach(function (): void {
    $this->phone = $this->loginAs('sarah@acme.test');
    $this->laptop = $this->loginAs('sarah@acme.test');
    $this->somebodyElse = $this->loginAs('ahmad@acme.test');

    $this->phoneId = explode('|', $this->phone)[0];
    $this->laptopId = explode('|', $this->laptop)[0];
});

it('lists every session that can act as me, and marks the one asking', function (): void {
    $sessions = $this->withToken($this->laptop)
        ->getJson('/api/v1/auth/sessions')
        ->assertOk()
        ->json('data');

    $ids = array_column($sessions, 'id');

    expect($ids)->toContain($this->phoneId)->toContain($this->laptopId);

    // Marked, never hidden. A list that omitted the device you are holding
    // would read as "somebody else is signed in here".
    $current = collect($sessions)->firstWhere('current', true);

    expect($current['id'])->toBe($this->laptopId)
        ->and(collect($sessions)->where('current', true))->toHaveCount(1);
});

it('shows nobody else\'s sessions', function (): void {
    $theirs = explode('|', $this->somebodyElse)[0];

    $ids = array_column(
        $this->withToken($this->laptop)->getJson('/api/v1/auth/sessions')->assertOk()->json('data'),
        'id',
    );

    expect($ids)->not->toContain($theirs);
});

it('ends another device immediately, not at token expiry', function (): void {
    $this->withToken($this->laptop)
        ->deleteJson("/api/v1/auth/sessions/{$this->phoneId}")
        ->assertNoContent();

    // Within one request: `SessionModel::findToken()` rejects a revoked row, so
    // the phone is signed out on its very next call rather than whenever its
    // 30-day token runs out.
    $this->withToken($this->phone)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $this->withToken($this->laptop)->getJson('/api/v1/auth/me')->assertOk();
});

it('records why the session ended', function (): void {
    // The reason was a parameter that went nowhere from Phase 1 to Phase 7:
    // every caller passed one and `revoke()` wrote `revoked_at` alone. It is
    // the whole of the answer in the one situation revoked rows are kept for —
    // somebody asking days later why they were signed out.
    $this->withToken($this->laptop)
        ->deleteJson("/api/v1/auth/sessions/{$this->phoneId}")
        ->assertNoContent();

    expect(DB::table('sessions')->where('id', $this->phoneId)->value('revoked_reason'))
        ->toBe('ended_from_another_device');

    $logged = DB::table('audit_logs')
        ->where('event', 'auth.session_revoked')
        ->exists();

    expect($logged)->toBeTrue();
});

it('refuses a session that belongs to somebody else', function (): void {
    $theirs = explode('|', $this->somebodyElse)[0];

    // 404, not 403: a session id is a uuid, and "that exists but is not yours"
    // confirms a guess about another account.
    $this->withToken($this->laptop)
        ->deleteJson("/api/v1/auth/sessions/{$theirs}")
        ->assertNotFound();

    $this->withToken($this->somebodyElse)->getJson('/api/v1/auth/me')->assertOk();
});

it('ends everything except the device asking', function (): void {
    $tablet = $this->loginAs('sarah@acme.test');

    $this->withToken($this->laptop)
        ->deleteJson('/api/v1/auth/sessions')
        ->assertOk()
        ->assertJsonPath('data.ended', 2);

    // The one it keeps is the point: a control that signed you out too would
    // leave you unable to change your password afterwards, which is the very
    // next thing that should happen.
    $this->withToken($this->laptop)->getJson('/api/v1/auth/me')->assertOk();
    $this->withToken($this->phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->withToken($tablet)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('stops listing a session once it has ended', function (): void {
    $this->withToken($this->laptop)->deleteJson("/api/v1/auth/sessions/{$this->phoneId}");

    $ids = array_column(
        $this->withToken($this->laptop)->getJson('/api/v1/auth/sessions')->assertOk()->json('data'),
        'id',
    );

    expect($ids)->not->toContain($this->phoneId);
});

it('knows when a session was last used', function (): void {
    // The column Sanctum writes on every authenticated request. It is the field
    // that makes the list answerable — "signed in three weeks ago and used
    // eleven minutes ago" is the sentence somebody reads before pressing End.
    $this->withToken($this->phone)->getJson('/api/v1/auth/me')->assertOk();

    $sessions = $this->withToken($this->laptop)
        ->getJson('/api/v1/auth/sessions')
        ->assertOk()
        ->json('data');

    $phone = collect($sessions)->firstWhere('id', $this->phoneId);

    expect($phone['last_used_at'])->not->toBeNull();
});

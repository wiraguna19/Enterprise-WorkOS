<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Signing out a session nobody is using (ADR 0029).
 *
 * `docs/06` has promised an idle timeout of "8 hours (org-configurable)" since
 * Phase 1 and nothing has ever measured idleness. `last_used_at` is written by
 * Sanctum on every authenticated request and was read by one thing: the session
 * list, to print a date. A timeout that exists in a specification and nowhere
 * in the product is the same defect as a permission nothing consults — and
 * worse, because the claim is about security.
 */
function setIdleWindow(?int $minutes): void
{
    DB::table('organizations')
        ->where('slug', 'acme')
        ->update(['idle_timeout_minutes' => $minutes]);
}

/** Age a session by rewriting the clock it is measured against. */
function ageSession(string $token, int $minutes): void
{
    DB::table('sessions')
        ->where('id', explode('|', $token)[0])
        ->update(['last_used_at' => now()->subMinutes($minutes)]);
}

it('leaves every session alone when no idle window is set', function (): void {
    setIdleWindow(null);

    $token = $this->loginAs('sarah@acme.test');
    ageSession($token, 60 * 24 * 30);

    // Off is the default, including for organizations that already existed. A
    // migration that signed out a company overnight to satisfy a number in a
    // document would not be a default, it would be an incident.
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('refuses a session that has been idle longer than the organization allows', function (): void {
    setIdleWindow(30);

    $token = $this->loginAs('sarah@acme.test');
    ageSession($token, 31);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('keeps a session that is still inside the window', function (): void {
    setIdleWindow(30);

    $token = $this->loginAs('sarah@acme.test');
    ageSession($token, 29);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('ends the idle session rather than only turning it away', function (): void {
    setIdleWindow(30);

    $token = $this->loginAs('sarah@acme.test');
    $id = explode('|', $token)[0];
    ageSession($token, 60);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

    // A row that authenticates nobody and still reads as live in Settings →
    // Signed in is the product lying about the one thing that screen exists to
    // answer — and the reason is what somebody asks about the next morning
    // (ADR 0023).
    $session = DB::table('sessions')->where('id', $id)->first();

    expect($session->revoked_at)->not->toBeNull()
        ->and($session->revoked_reason)->toBe('idle_timeout');
});

it('ages a session that was issued and never used from when it was issued', function (): void {
    setIdleWindow(30);

    $token = $this->loginAs('sarah@acme.test');

    // Login writes `created_at`; `last_used_at` stays null until the session
    // makes its first authenticated request. Without the fallback, a session
    // nobody ever used would be the one session that never ages out.
    DB::table('sessions')->where('id', explode('|', $token)[0])->update([
        'last_used_at' => null,
        'created_at' => now()->subHours(3),
    ]);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('measures idleness from the last request, not from sign-in', function (): void {
    setIdleWindow(30);

    $token = $this->loginAs('sarah@acme.test');

    DB::table('sessions')->where('id', explode('|', $token)[0])->update([
        'created_at' => now()->subDays(20),
        // Set explicitly, because login does NOT write it: the token is issued
        // before anything authenticates with it, so `last_used_at` is null
        // until the session's first request and the fallback above would
        // otherwise read this as twenty days idle. The first version of this
        // test forgot that and asserted the opposite of what it set up.
        'last_used_at' => now()->subMinute(),
    ]);

    // Twenty days old and used a minute ago is an ACTIVE session. Conflating
    // age with idleness would sign out the person who never stops working.
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('applies one organization\'s idle window to nobody else\'s sessions', function (): void {
    setIdleWindow(30);

    $globex = $this->loginAs('gil@globex.test');
    ageSession($globex, 120);

    $this->withToken($globex)->getJson('/api/v1/auth/me')->assertOk();
});

it('refuses an idle window the database would refuse', function (): void {
    $admin = $this->loginAs('rina@acme.test');

    foreach ([1, 20161] as $minutes) {
        $this->withToken($admin)
            ->patchJson('/api/v1/organization/settings/session-policy', [
                'session_lifetime_days' => 30,
                'idle_timeout_minutes' => $minutes,
            ])
            ->assertUnprocessable();
    }
});

it('leaves the idle window alone when the request does not mention it', function (): void {
    setIdleWindow(60);

    $this->withToken($this->loginAs('rina@acme.test'))
        ->patchJson('/api/v1/organization/settings/session-policy', ['session_lifetime_days' => 14])
        ->assertOk();

    // A PATCH that read a missing key as "off" would switch the idle timeout
    // off every time somebody changed the lifetime beside it.
    expect(DB::table('organizations')->where('slug', 'acme')->value('idle_timeout_minutes'))
        ->toBe(60);
});

it('switches the idle window off when the request says null', function (): void {
    setIdleWindow(60);

    $this->withToken($this->loginAs('rina@acme.test'))
        ->patchJson('/api/v1/organization/settings/session-policy', [
            'session_lifetime_days' => 30,
            'idle_timeout_minutes' => null,
        ])
        ->assertOk();

    expect(DB::table('organizations')->where('slug', 'acme')->value('idle_timeout_minutes'))
        ->toBeNull();
});

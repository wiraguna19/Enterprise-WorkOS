<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * How long a session may live here, decided by the organization (ADR 0028).
 *
 * Thirty days was written in `AuthenticationService` in Phase 1 and was the
 * same for every tenant in the product. ADR 0023 shipped the half of "session
 * policy controls" a person can see and recorded this half as owed.
 *
 * Two permissions are paid off here as well, and both had been ticked in the
 * role builder since Phase 1 with nothing on the server asking about them —
 * `organization.view` while the web nav hid its Settings entry behind it, which
 * is the version of this defect that makes a product LOOK enforced.
 */
it('gives the organization default to a session signed in with nothing set', function (): void {
    $token = $this->loginAs('sarah@acme.test');

    $expires = DB::table('sessions')->where('id', explode('|', $token)[0])->value('expires_at');

    expect(now()->diffInDays($expires, absolute: true))->toBeGreaterThan(29);
});

it('issues the next session under the window the organization chose', function (): void {
    $this->withToken($this->loginAs('rina@acme.test'))
        ->patchJson('/api/v1/organization/settings/session-policy', ['session_lifetime_days' => 7])
        ->assertOk();

    $token = $this->loginAs('sarah@acme.test');
    $expires = DB::table('sessions')->where('id', explode('|', $token)[0])->value('expires_at');

    expect(now()->diffInDays($expires, absolute: true))->toBeLessThan(8);
});

/**
 * The reason this slice is not a one-line change to a constant.
 *
 * A policy that governs only sessions issued after it was set is not a policy
 * for thirty days. An administrator who shortens the window because a laptop
 * went missing has, without this, changed nothing at all about the laptop.
 */
it('pulls back sessions that already outlive the new window', function (): void {
    $missingLaptop = $this->loginAs('sarah@acme.test');
    $laptopId = explode('|', $missingLaptop)[0];

    $response = $this->withToken($this->loginAs('rina@acme.test'))
        ->patchJson('/api/v1/organization/settings/session-policy', ['session_lifetime_days' => 1])
        ->assertOk();

    expect($response->json('data.sessions_shortened'))->toBeGreaterThan(0);

    $expires = DB::table('sessions')->where('id', $laptopId)->value('expires_at');

    expect(now()->diffInHours($expires, absolute: true))->toBeLessThanOrEqual(24);

    // Shortened, NOT revoked. "Adjust the window" and "sign my whole company
    // out right now" are different acts, and a setting that quietly did the
    // second would be pressed once and never again.
    $this->withToken($missingLaptop)->getJson('/api/v1/auth/me')->assertOk();
});

it('does not extend sessions that already exist when the window is raised', function (): void {
    $admin = $this->loginAs('rina@acme.test');

    $this->withToken($admin)
        ->patchJson('/api/v1/organization/settings/session-policy', ['session_lifetime_days' => 7])
        ->assertOk();

    $token = $this->loginAs('sarah@acme.test');
    $sessionId = explode('|', $token)[0];

    $this->withToken($admin)
        ->patchJson('/api/v1/organization/settings/session-policy', ['session_lifetime_days' => 90])
        ->assertOk();

    // A session issued under a seven-day promise stays a seven-day session.
    // Stretching it hands out access nobody reviewed; the new number governs
    // the next sign-in, which is the moment somebody proves who they are again.
    $expires = DB::table('sessions')->where('id', $sessionId)->value('expires_at');

    expect(now()->diffInDays($expires, absolute: true))->toBeLessThan(8);
});

it('does not revive a session that has already expired', function (): void {
    $stale = $this->loginAs('sarah@acme.test');
    $staleId = explode('|', $stale)[0];

    DB::table('sessions')->where('id', $staleId)->update(['expires_at' => now()->subDay()]);

    $this->withToken($this->loginAs('rina@acme.test'))
        ->patchJson('/api/v1/organization/settings/session-policy', ['session_lifetime_days' => 90])
        ->assertOk();

    // Without the `expires_at > limit` filter on the clamp, this statement
    // would push every dead row in the organization ninety days into the
    // future: a policy change that signs people back in.
    $this->withToken($stale)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('refuses a window the database would refuse', function (): void {
    $admin = $this->loginAs('rina@acme.test');

    $this->withToken($admin)
        ->patchJson('/api/v1/organization/settings/session-policy', ['session_lifetime_days' => 0])
        ->assertUnprocessable();

    $this->withToken($admin)
        ->patchJson('/api/v1/organization/settings/session-policy', ['session_lifetime_days' => 365])
        ->assertUnprocessable();
});

it('lets somebody read the settings without letting them change one', function (): void {
    // Sarah is an employee: `organization.view` is in the seeded employee role,
    // `organization.manage_settings` is not.
    $sarah = $this->loginAs('sarah@acme.test');

    $this->withToken($sarah)->getJson('/api/v1/organization/settings')->assertOk();

    $this->withToken($sarah)
        ->patchJson('/api/v1/organization/settings/session-policy', ['session_lifetime_days' => 90])
        ->assertForbidden();
});

it('keeps one organization out of another one\'s policy', function (): void {
    $globex = DB::table('organizations')->where('slug', 'globex')->value('id');
    $before = DB::table('organizations')->where('id', $globex)->value('session_lifetime_days');

    $this->withToken($this->loginAs('rina@acme.test'))
        ->patchJson('/api/v1/organization/settings/session-policy', ['session_lifetime_days' => 1])
        ->assertOk();

    expect(DB::table('organizations')->where('id', $globex)->value('session_lifetime_days'))
        ->toBe($before);
});

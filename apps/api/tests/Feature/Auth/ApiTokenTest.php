<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Service\SessionLifetime;
use Illuminate\Support\Facades\DB;

/**
 * Public API tokens (ADR 0049).
 *
 * A token is a session row with `kind = 'api_token'`, so most of what it must
 * do it does for free. These tests are about the four places it must NOT
 * behave like a session — idle timeout, lifetime clamp, credential routes,
 * writes when read-only — and about the one thing it must never become: a way
 * to do more than the person who made it.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');      // api_token.create
    $this->employee = $this->loginAs('sarah@acme.test');  // not
});

/**
 * Make one and hand back [id, value].
 *
 * @return array{0: string, 1: string}
 */
function makeApiToken(string $session, string $access = 'read', int $days = 30): array
{
    $data = test()->withToken($session)
        ->postJson('/api/v1/me/api-tokens', [
            'name' => 'CI export '.$access,
            'access' => $access,
            'expires_in_days' => $days,
        ])
        ->assertStatus(201)
        ->json('data');

    return [(string) $data['id'], (string) $data['token']];
}

it('makes a token, shows its value once, and stores only a digest', function (): void {
    [$id, $value] = makeApiToken($this->admin);

    expect($value)->toContain('|wos_');

    $listed = collect($this->withToken($this->admin)
        ->getJson('/api/v1/me/api-tokens')
        ->assertOk()
        ->json('data'))
        ->firstWhere('id', $id);

    expect($listed['access'])->toBe('read')
        ->and($listed)->not->toHaveKey('token')
        ->and(json_encode($listed))->not->toContain(explode('|', $value)[1]);

    $row = DB::table('sessions')->where('id', $id)->first();

    expect($row->kind)->toBe('api_token')
        ->and($row->token_hash)->toBe(hash('sha256', explode('|', $value)[1]))
        ->and($row->reauthenticated_at)->toBeNull();
});

it('acts as its author, in its author\'s organization', function (): void {
    [, $value] = makeApiToken($this->admin);

    $this->withToken($value)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', 'rina@acme.test');

    $this->withToken($value)->getJson('/api/v1/work-items?limit=1')->assertOk();
});

it('keeps a read-only token to reading', function (): void {
    [, $value] = makeApiToken($this->admin, 'read');

    $this->withToken($value)
        ->postJson('/api/v1/work-items', ['title' => 'From a script'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'auth.token_read_only');
});

it('lets a read and write token write, within its author\'s permissions', function (): void {
    [, $value] = makeApiToken($this->admin, 'read_write');

    $this->withToken($value)
        ->postJson('/api/v1/work-items', ['title' => 'From a script'])
        ->assertStatus(201);
});

it('keeps every token away from credentials — sessions, second factors, re-authentication, tokens', function (string $method, string $uri): void {
    [, $value] = makeApiToken($this->admin, 'read_write');

    $this->withToken($value)
        ->json($method, $uri, [
            'name' => 'Minted by a token',
            'access' => 'read_write',
            'expires_in_days' => 365,
            'password' => 'password',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'auth.interactive_session_required');
})->with([
    'minting another token' => ['POST', '/api/v1/me/api-tokens'],
    'listing tokens' => ['GET', '/api/v1/me/api-tokens'],
    'the session list' => ['GET', '/api/v1/auth/sessions'],
    'ending other sessions' => ['DELETE', '/api/v1/auth/sessions'],
    're-authenticating' => ['POST', '/api/v1/auth/reauthenticate'],
    'enrolling a second factor' => ['POST', '/api/v1/auth/mfa'],
]);

it('does not go idle, where a browser session would', function (): void {
    [$id, $value] = makeApiToken($this->admin);

    DB::table('organizations')
        ->where('id', '01900000-0000-7000-8000-0000000000ac')
        ->update(['idle_timeout_minutes' => 5]);

    DB::table('sessions')->where('id', $id)->update(['last_used_at' => now()->subDay()]);

    $this->withToken($value)->getJson('/api/v1/auth/me')->assertOk();

    expect(DB::table('sessions')->where('id', $id)->value('revoked_at'))->toBeNull();
});

it('is not shortened when the organization shortens its sessions', function (): void {
    [$id] = makeApiToken($this->admin, 'read', 365);

    app(SessionLifetime::class)->clampTo('01900000-0000-7000-8000-0000000000ac', 1);

    $expires = DB::table('sessions')->where('id', $id)->value('expires_at');

    expect(now()->diffInDays($expires))->toBeGreaterThan(300);
});

it('is not listed among the devices you are signed in on', function (): void {
    [$id] = makeApiToken($this->admin);

    $ids = collect($this->withToken($this->admin)
        ->getJson('/api/v1/auth/sessions')
        ->assertOk()
        ->json('data'))
        ->pluck('id')
        ->all();

    expect($ids)->not->toContain($id);
});

it('stops working the moment it is revoked', function (): void {
    [$id, $value] = makeApiToken($this->admin);

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/me/api-tokens/{$id}")
        ->assertNoContent();

    $this->withToken($value)->getJson('/api/v1/auth/me')->assertUnauthorized();

    expect(DB::table('sessions')->where('id', $id)->value('revoked_reason'))->toBe('api_token_revoked');
});

it('cannot be used to end somebody else\'s token, or anybody\'s session', function (): void {
    [$id] = makeApiToken($this->admin);

    // Sarah may revoke her own tokens without the permission to make one —
    // and nobody else's.
    $this->withToken($this->employee)
        ->deleteJson("/api/v1/me/api-tokens/{$id}")
        ->assertNotFound();

    // A SESSION id through the token route is not a token.
    $sessionId = (string) DB::table('sessions')
        ->where('kind', 'session')
        ->whereNull('revoked_at')
        ->orderByDesc('created_at')
        ->value('id');

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/me/api-tokens/{$sessionId}")
        ->assertNotFound();
});

it('is refused to somebody whose role does not allow making one', function (): void {
    $this->withToken($this->employee)
        ->postJson('/api/v1/me/api-tokens', ['name' => 'Mine', 'access' => 'read', 'expires_in_days' => 30])
        ->assertForbidden();

    // Listing their own (none) still works.
    $this->withToken($this->employee)->getJson('/api/v1/me/api-tokens')->assertOk();
});

it('offers three lifetimes and two levels of access, and nothing else', function (array $body): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/me/api-tokens', $body + ['name' => 'Odd'])
        ->assertStatus(422);
})->with([
    'forever' => [['access' => 'read', 'expires_in_days' => 36500]],
    'a custom scope' => [['access' => 'admin', 'expires_in_days' => 30]],
]);

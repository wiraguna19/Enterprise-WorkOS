<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use Illuminate\Support\Facades\DB;

/**
 * Every endpoint gets happy path, validation, authorization denial, tenant
 * isolation, and side effects (docs/11 §3). An authorization test that only
 * asserts the allowed case is not a test.
 */
it('issues a session for valid credentials', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['token', 'expires_at', 'user' => ['id', 'name', 'email'], 'organization' => ['id', 'slug']],
            'meta' => ['request_id'],
        ]);

    expect($response->json('data.organization.slug'))->toBe('acme');
});

it('never returns the password hash', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test',
        'password' => 'password',
    ]);

    expect(json_encode($response->json()))
        ->not->toContain('password_hash')
        ->not->toContain('$2y$');
});

it('stores only the token digest, never the token', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test',
        'password' => 'password',
    ]);

    [$id, $secret] = explode('|', $response->json('data.token'), 2);

    $stored = DB::table('sessions')->where('id', $id)->value('token_hash');

    expect($stored)->toBe(hash('sha256', $secret))
        ->and($stored)->not->toBe($secret);
});

it('gives the same answer for a wrong password and an unknown account', function (): void {
    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test', 'password' => 'not-the-password',
    ]);

    $unknownUser = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@acme.test', 'password' => 'not-the-password',
    ]);

    // Any difference here is a user enumeration oracle (docs/06 §1).
    $wrongPassword->assertStatus(401);
    $unknownUser->assertStatus(401);

    expect($wrongPassword->json('error.code'))->toBe($unknownUser->json('error.code'));
    expect($wrongPassword->json('error.message'))->toBe($unknownUser->json('error.message'));
});

it('refuses a revoked membership', function (): void {
    // Eko Prasetyo is seeded with a revoked membership.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'former@acme.test', 'password' => 'password',
    ])->assertStatus(403)
        ->assertJsonPath('error.code', 'auth.no_active_membership');
});

it('rate limits repeated failures', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'sarah@acme.test', 'password' => 'wrong',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'sarah@acme.test', 'password' => 'wrong',
    ])->assertStatus(429);
});

it('validates the payload', function (array $payload, string $field): void {
    $this->postJson('/api/v1/auth/login', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing email' => [['password' => 'password'], 'email'],
    'missing password' => [['email' => 'sarah@acme.test'], 'password'],
    'malformed email' => [['email' => 'not-an-email', 'password' => 'password'], 'email'],
]);

it('writes an audit record on success and on failure', function (): void {
    $this->postJson('/api/v1/auth/login', ['email' => 'sarah@acme.test', 'password' => 'password']);
    $this->postJson('/api/v1/auth/login', ['email' => 'sarah@acme.test', 'password' => 'wrong']);

    expect(DB::table('audit_logs')->where('event', 'auth.login')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('event', 'auth.login_failed')->count())->toBe(1);
});

it('revokes the session on logout and the token stops working', function (): void {
    $token = $this->loginAs('sarah@acme.test');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    // The core reason sessions are opaque rather than JWTs: revocation is
    // immediate, not at expiry (docs/06 §1).
    // Asserted at the row as well as through the API: if these two ever
    // disagree, the interesting question is which one is lying.
    expect(DB::table('sessions')->where('id', explode('|', $token)[0])->value('revoked_at'))
        ->not->toBeNull('logout did not write revoked_at');

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('rejects an expired session', function (): void {
    $token = $this->loginAs('sarah@acme.test');
    [$id] = explode('|', $token, 2);

    SessionModel::query()->where('id', $id)->update(['expires_at' => now()->subMinute()]);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('returns identity, organization and permissions from /me', function (): void {
    $token = $this->loginAs('ahmad@acme.test');

    $response = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    expect($response->json('data.user.email'))->toBe('ahmad@acme.test')
        ->and($response->json('data.organization.slug'))->toBe('acme')
        ->and($response->json('data.permissions'))->toContain('team.create')
        ->and($response->json('data.permissions'))->not->toContain('role.manage');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * A person's interface language (ADR 0060).
 *
 * `users.locale` was served by `/auth/me` from the first migration and nothing
 * could change it. These hold that a person can, that only the two languages
 * the web speaks are accepted, and that a script cannot.
 */
beforeEach(function (): void {
    $this->sarah = $this->loginAs('sarah@acme.test');
});

it('changes the language and answers with the same payload as reading it', function (): void {
    $this->withToken($this->sarah)
        ->patchJson('/api/v1/auth/me', ['locale' => 'id'])
        ->assertOk()
        ->assertJsonPath('data.user.locale', 'id')
        // The whole bootstrap payload, so the caller need not read it again.
        ->assertJsonStructure(['data' => ['user', 'membership', 'organization', 'permissions']]);

    $this->withToken($this->sarah)
        ->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.user.locale', 'id');

    expect(DB::table('users')->where('email', 'sarah@acme.test')->value('locale'))->toBe('id');
});

it('accepts only a language the interface speaks', function (): void {
    $this->withToken($this->sarah)
        ->patchJson('/api/v1/auth/me', ['locale' => 'fr'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    $this->withToken($this->sarah)
        ->patchJson('/api/v1/auth/me', [])
        ->assertUnprocessable();
});

it('is refused to an API token', function (): void {
    $token = (string) $this->withToken($this->loginAs('rina@acme.test'))
        ->postJson('/api/v1/me/api-tokens', [
            'name' => 'Language probe',
            'access' => 'read_write',
            'expires_in_days' => 30,
        ])
        ->assertStatus(201)
        ->json('data.token');

    $this->withToken($token)
        ->patchJson('/api/v1/auth/me', ['locale' => 'id'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'auth.interactive_session_required');
});

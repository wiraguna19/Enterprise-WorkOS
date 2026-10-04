<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * A person's reading size, kept on the account so it follows them to every
 * device. Saved through the same PATCH as the language; each screen sends only
 * the preference it is about, and one must not overwrite the other.
 */
beforeEach(function (): void {
    $this->sarah = $this->loginAs('sarah@acme.test');
});

it('starts at the normal size', function (): void {
    $this->withToken($this->sarah)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.text_size', 'normal');
});

it('changes the size without touching the language', function (): void {
    $this->withToken($this->sarah)
        ->patchJson('/api/v1/auth/me', ['text_size' => 'larger'])
        ->assertOk()
        ->assertJsonPath('data.user.text_size', 'larger')
        ->assertJsonPath('data.user.locale', 'en');

    $row = DB::table('users')->where('email', 'sarah@acme.test')->first(['text_size', 'locale']);

    expect($row->text_size)->toBe('larger')
        ->and($row->locale)->toBe('en');
});

it('accepts only a size the interface is laid out at', function (): void {
    $this->withToken($this->sarah)
        ->patchJson('/api/v1/auth/me', ['text_size' => 'huge'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');
});

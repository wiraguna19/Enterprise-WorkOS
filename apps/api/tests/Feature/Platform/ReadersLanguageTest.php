<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Language\Locales;

/**
 * The API answers in the language a request asks for (ADR 0060).
 *
 * The web sends the reader's language with every call; a client that sends
 * none gets English, exactly as before. Untranslated sentences fall back to
 * English by themselves, so these assert the path rather than every string.
 */
it('reads the first supported language by weight, region ignored', function (string $header, string $expected): void {
    expect(Locales::fromAcceptLanguage($header))->toBe($expected);
})->with([
    'plain' => ['id', 'id'],
    'with a region' => ['id-ID', 'id'],
    'a browser header' => ['id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7', 'id'],
    'weights decide, not order' => ['en;q=0.5, id;q=0.9', 'id'],
    'ties keep the order given' => ['en, id', 'en'],
    'an unsupported language' => ['fr-FR, de;q=0.8', 'en'],
    'unsupported first, supported later' => ['fr, id;q=0.4', 'id'],
    'refused with q=0' => ['id;q=0, en;q=0.1', 'en'],
    'empty' => ['', 'en'],
]);

it('refuses in Indonesian when asked', function (): void {
    $this->withHeader('Accept-Language', 'id-ID')
        ->postJson('/api/v1/auth/login', ['email' => 'rina@acme.test', 'password' => 'not-her-password'])
        ->assertStatus(401)
        ->assertHeader('Content-Language', 'id')
        ->assertJsonPath('error.message', 'Email atau kata sandi tidak cocok dengan catatan kami.');
});

it('refuses in English when nothing is asked', function (): void {
    $this->postJson('/api/v1/auth/login', ['email' => 'rina@acme.test', 'password' => 'not-her-password'])
        ->assertStatus(401)
        ->assertHeader('Content-Language', 'en')
        ->assertJsonPath('error.message', 'These credentials do not match our records.');
});

it('words validation in Indonesian, with the field named as a person reads it', function (): void {
    $response = $this->withHeader('Accept-Language', 'id')
        ->postJson('/api/v1/auth/login', ['password' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Data yang dikirim tidak valid.');

    expect($response->json('error.details.email.0'))->toBe('email wajib diisi.');
});

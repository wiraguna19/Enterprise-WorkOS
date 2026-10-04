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

it('words a refusal that carries a name, in Indonesian', function (): void {
    // A seeded role is refused by name; the name stays as it is, the sentence
    // around it moves to the reader's language.
    $admin = $this->loginAs('rina@acme.test');

    $this->withToken($admin)
        ->withHeader('Accept-Language', 'id')
        ->deleteJson('/api/v1/roles/employee')
        ->assertStatus(409)
        ->assertJsonPath(
            'error.message',
            '`employee` adalah salah satu role bawaan produk ini dan tidak bisa dihapus.',
        );
});

it('keeps every placeholder of a sentence in its translation', function (): void {
    // A translation that drops `:count` prints the sentence without the number;
    // one that adds a placeholder prints it literally. Neither fails anywhere
    // else, so it fails here.
    $dictionary = json_decode((string) file_get_contents(lang_path('id.json')), true);
    $placeholders = static function (string $text): array {
        preg_match_all('/:([a-zA-Z_]+)/', $text, $found);

        return collect($found[1])->map(fn (string $name): string => strtolower($name))->unique()->sort()->values()->all();
    };

    $mismatched = collect($dictionary)
        ->filter(fn (string $translation, string $english): bool => $placeholders($english) !== $placeholders($translation))
        ->keys()
        ->all();

    expect($mismatched)->toBe([]);
});

it('has an Indonesian sentence for every sentence the API translates', function (): void {
    // A new __('…') with no entry still works — it falls back to English — so
    // nothing else would ever notice it. This does.
    $dictionary = json_decode((string) file_get_contents(lang_path('id.json')), true);
    $missing = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        preg_match_all("/__\\(\\s*'((?:\\\\.|[^'\\\\])*)'/", $source, $single);
        preg_match_all('/__\\(\\s*"((?:\\\\.|[^"\\\\])*)"/', $source, $double);

        foreach ([...array_map(fn (string $s): string => str_replace("\\'", "'", $s), $single[1]), ...array_map('stripslashes', $double[1])] as $sentence) {
            if (! array_key_exists($sentence, $dictionary)) {
                $missing[] = $sentence;
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});

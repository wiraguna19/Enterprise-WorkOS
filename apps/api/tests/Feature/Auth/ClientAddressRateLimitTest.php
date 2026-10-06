<?php

declare(strict_types=1);

/**
 * Login limits are kept per CLIENT, not per web server.
 *
 * The web app calls the API itself, so before this every request arrived from
 * one address and the per-address limit was one limit shared by everybody:
 * twenty failed logins from anyone locked the whole product out. The web app
 * now forwards the client's address, and the API believes it only from an
 * address in TRUSTED_PROXIES (127.0.0.1 here, which is where tests connect
 * from).
 */
function failLogin(string $client, string $email = 'sarah@acme.test', string $from = '127.0.0.1'): int
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $from])
        ->withHeaders(['X-Forwarded-For' => $client])
        ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'wrong'])
        ->status();
}

it('keeps one client\'s failures from locking out another', function (): void {
    foreach (range(1, 5) as $attempt) {
        expect(failLogin('203.0.113.10'))->toBe(401);
    }

    expect(failLogin('203.0.113.10'))->toBe(429)
        // Same account, another client: still allowed to try.
        ->and(failLogin('198.51.100.20'))->toBe(401);
});

it('stops many clients guessing one account', function (): void {
    foreach (range(1, 20) as $n) {
        expect(failLogin("198.51.100.{$n}"))->toBe(401);
    }

    // A fresh address gains nothing once the account has had its twenty.
    expect(failLogin('198.51.100.99'))->toBe(429);
});

it('ignores the header from anyone it does not trust', function (): void {
    // A direct caller from an untrusted address cannot rotate its identity by
    // changing the header: its own address is what counts.
    foreach (range(1, 5) as $n) {
        expect(failLogin("192.0.2.{$n}", from: '10.9.9.9'))->toBe(401);
    }

    expect(failLogin('192.0.2.77', from: '10.9.9.9'))->toBe(429);
});

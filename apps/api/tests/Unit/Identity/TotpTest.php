<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Support\Totp;

/**
 * The test that makes writing TOTP out safe (ADR 0030).
 *
 * These vectors are RFC 6238's own, from appendix B, not from this
 * implementation — which is the whole argument for forty lines instead of a
 * dependency. An implementation that agrees with the RFC at these six moments
 * agrees with every authenticator app in the world.
 *
 * The RFC prints eight digits; this product uses six, so each expectation is
 * the last six of the published value.
 */
const RFC_SECRET = '12345678901234567890';

dataset('rfc 6238 vectors', [
    [59, '287082'],
    [1111111109, '081804'],
    [1111111111, '050471'],
    [1234567890, '005924'],
    [2000000000, '279037'],
    [20000000000, '353130'],
]);

it('agrees with RFC 6238', function (int $timestamp, string $expected): void {
    $secret = Totp::base32Encode(RFC_SECRET);

    expect(Totp::at($secret, $timestamp))->toBe($expected);
})->with('rfc 6238 vectors');

it('round-trips a secret through base32', function (): void {
    $bytes = random_bytes(20);

    expect(Totp::base32Decode(Totp::base32Encode($bytes)))->toBe($bytes);
});

it('accepts the code from one period either side', function (): void {
    $secret = Totp::generateSecret();
    $now = time();

    // Thirty seconds of drift in each direction, which is what makes the
    // difference between a working product and one that refuses anybody whose
    // phone clock is a second off or who types slowly.
    expect(Totp::verify($secret, Totp::at($secret, $now - Totp::PERIOD), $now))->toBeTrue()
        ->and(Totp::verify($secret, Totp::at($secret, $now + Totp::PERIOD), $now))->toBeTrue();
});

it('refuses a code two periods away', function (): void {
    $secret = Totp::generateSecret();
    $now = time();

    expect(Totp::verify($secret, Totp::at($secret, $now - 2 * Totp::PERIOD), $now))->toBeFalse();
});

it('refuses anything that is not six digits', function (string $code): void {
    expect(Totp::verify(Totp::generateSecret(), $code))->toBeFalse();
})->with(['', '12345', '1234567', 'abcdef', '      ']);

it('ignores the spaces an authenticator app puts in the middle', function (): void {
    $secret = Totp::generateSecret();
    $now = time();
    $code = Totp::at($secret, $now);

    // Apps display "123 456", and somebody copying it brings the space along.
    expect(Totp::verify($secret, substr($code, 0, 3).' '.substr($code, 3), $now))->toBeTrue();
});

it('names the account and the issuer in the provisioning URI', function (): void {
    $uri = Totp::provisioningUri('ABCDEFGH', 'sarah@acme.test', 'Work OS');

    // The issuer appears twice because apps disagree about which one they
    // read, and an account listed as "unknown" is one somebody deletes by
    // accident.
    expect($uri)->toStartWith('otpauth://totp/Work%20OS:sarah%40acme.test?')
        ->and($uri)->toContain('issuer=Work+OS')
        ->and($uri)->toContain('secret=ABCDEFGH')
        ->and($uri)->toContain('digits=6')
        ->and($uri)->toContain('period=30');
});

it('says which period a code belongs to, not when it was checked', function (): void {
    $secret = Totp::generateSecret();
    $now = time();
    $period = intdiv($now, Totp::PERIOD);

    // The distinction the replay rule stands on: a code read in the previous
    // period answers with THAT period, even though it is being checked in this
    // one and is still perfectly valid.
    expect(Totp::match($secret, Totp::at($secret, $now), $now))->toBe($period)
        ->and(Totp::match($secret, Totp::at($secret, $now - Totp::PERIOD), $now))->toBe($period - 1)
        ->and(Totp::match($secret, Totp::at($secret, $now + Totp::PERIOD), $now))->toBe($period + 1)
        ->and(Totp::match($secret, '000000', $now - 10 ** 9))->toBeNull();
});

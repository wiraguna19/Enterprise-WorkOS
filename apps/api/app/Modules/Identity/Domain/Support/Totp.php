<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Support;

/**
 * RFC 6238 time-based one-time passwords, written out rather than installed
 * (ADR 0030).
 *
 * Forty lines against a specification that has not changed since 2011, with the
 * RFC's own test vectors in the suite. A dependency would be more code in the
 * vendor directory than there is here, and the part that actually matters —
 * the comparison, the window, the drift — would still have to be understood by
 * whoever reads this file. The same argument as the six icons in ADR 0027, with
 * one difference worth stating: this is authentication, so the reason it is
 * safe to write out is that it is *verifiable*. The vectors in
 * `TotpTest` are from the RFC's appendix, not from this implementation.
 *
 * Deliberately NOT in this class: rate limiting (the route throttles), replay
 * prevention (see `MultiFactor`), and storage. It computes and compares codes,
 * and nothing else.
 */
final class Totp
{
    /** Seconds per code. Thirty is what every authenticator app assumes. */
    public const PERIOD = 30;

    public const DIGITS = 6;

    /**
     * How many periods either side of now are accepted.
     *
     * One, which is the usual answer and worth being explicit about: it makes
     * a code valid for between 30 and 90 seconds depending on when in its
     * period it was read. Zero rejects anybody whose phone clock is a second
     * off or who types slowly; two widens the guessing window for no gain
     * people would notice.
     */
    public const DRIFT = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A fresh secret, base32 as every authenticator app expects it.
     *
     * Twenty bytes because that is the SHA-1 block the RFC's own vectors use,
     * and because every app in the world has been reading 160-bit secrets since
     * 2011.
     *
     * @param  int<1, max>  $bytes
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** The code for one moment, for one secret. */
    public static function at(string $secret, int $timestamp, int $offset = 0): string
    {
        $counter = intdiv($timestamp, self::PERIOD) + $offset;

        // The counter is a 64-bit big-endian integer. `J` is unsigned 64-bit
        // big-endian, which needs PHP 8's guarantee of a 64-bit int — the
        // project requires 8.3.
        $hash = hash_hmac('sha1', pack('J', $counter), self::base32Decode($secret), binary: true);

        // Dynamic truncation, RFC 4226 §5.4: the low nibble of the last byte
        // picks where in the digest to read four bytes from.
        $start = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$start]) & 0x7F) << 24)
            | (ord($hash[$start + 1]) << 16)
            | (ord($hash[$start + 2]) << 8)
            | ord($hash[$start + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Does this code belong to this secret, near enough to now?
     *
     * `hash_equals` on every comparison: a `===` here leaks, through timing,
     * how many leading digits of a guess were right, which turns a million
     * possibilities into six thousand.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        return self::match($secret, $code, $timestamp) !== null;
    }

    /**
     * WHICH period's code this is, or null.
     *
     * The counter, not merely a yes — and the difference is a defect somebody
     * found by using the product. Recording "a code was accepted at period C"
     * makes the code from C-1 usable again at C, because it is still inside the
     * drift window and the counter has moved on. What has to be remembered is
     * the period the CODE belongs to, so a spent code stays spent for as long
     * as it would otherwise be valid.
     *
     * Every offset is compared even after a match, and the matched counter is
     * kept rather than returned early, so the time this takes says nothing
     * about which period matched — or whether any did.
     */
    public static function match(string $secret, string $code, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $timestamp ??= time();
        $base = intdiv($timestamp, self::PERIOD);
        $matched = null;

        for ($offset = -self::DRIFT; $offset <= self::DRIFT; $offset++) {
            if (hash_equals(self::at($secret, $timestamp, $offset), $code)) {
                $matched = $base + $offset;
            }
        }

        return $matched;
    }

    /**
     * The URI an authenticator app reads from a QR code.
     *
     * The issuer appears twice — in the label and as a parameter — because
     * apps disagree about which one they read, and an account listed as
     * "unknown" is one a person deletes by accident.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            // `bindec` widens to float past PHP_INT_MAX, which five bits
            // cannot reach — the cast tells the analyser what the arithmetic
            // already guarantees.
            $encoded .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        // No `=` padding. It is legal base32 without it, every authenticator
        // app accepts it, and padding in a URL query is one more thing to get
        // wrong for no benefit.
        return $encoded;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = rtrim(strtoupper($secret), '=');
        $bits = '';

        foreach (str_split($secret) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        // Trailing bits that do not make a whole byte are padding, not data.
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                // `pack('C')` rather than `chr()`: eight bits cannot leave
                // 0–255, but only `pack` says so in a type the analyser can
                // read, and it is the same instruction used for the counter
                // above.
                $bytes .= pack('C', (int) bindec($chunk));
            }
        }

        return $bytes;
    }
}

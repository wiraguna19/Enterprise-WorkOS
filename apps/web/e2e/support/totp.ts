import { createHmac } from "node:crypto";

/**
 * RFC 6238, for the one flow that has to be its own authenticator app.
 *
 * Written out rather than installed, for the reason the server's own TOTP is
 * (ADR 0030): it is thirty lines of arithmetic, and a dependency added to the
 * web app for a test would be a dependency the product ships.
 *
 * `counter` is explicit because the server refuses a code for a period it has
 * already accepted: enrolment spends the current period, so the sign-in that
 * follows asks for the NEXT one — which the server accepts, one period ahead
 * being inside its window — instead of waiting up to thirty seconds.
 */
export function currentCounter(now: number = Date.now()): number {
  return Math.floor(now / 1000 / 30);
}

export function totp(secret: string, counter: number): string {
  const key = base32Decode(secret);
  const message = Buffer.alloc(8);

  message.writeBigUInt64BE(BigInt(counter));

  const digest = createHmac("sha1", key).update(message).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const binary =
    ((digest[offset] & 0x7f) << 24) |
    ((digest[offset + 1] & 0xff) << 16) |
    ((digest[offset + 2] & 0xff) << 8) |
    (digest[offset + 3] & 0xff);

  return String(binary % 1_000_000).padStart(6, "0");
}

function base32Decode(input: string): Buffer {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  const clean = input.replace(/[\s=]/g, "").toUpperCase();

  let bits = 0;
  let value = 0;
  const bytes: number[] = [];

  for (const character of clean) {
    const index = alphabet.indexOf(character);

    if (index === -1) throw new Error(`Not base32: "${character}" in the enrolment key.`);

    value = (value << 5) | index;
    bits += 5;

    if (bits >= 8) {
      bytes.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
  }

  return Buffer.from(bytes);
}

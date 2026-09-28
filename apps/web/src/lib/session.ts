import { cookies } from "next/headers";
import {
  MFA_CHALLENGE_COOKIE,
  SESSION_COOKIE,
  SESSION_COOKIE_SECURE,
  SSO_BINDING_COOKIE,
} from "./session-cookie";

/**
 * The session token lives in an HttpOnly cookie set by THIS server and is
 * forwarded to the API as a bearer token. It never enters the browser's
 * JavaScript heap, which removes the entire "XSS steals the token" class of
 * attack (docs/06 §1).
 *
 * Nothing in `src/features` or `src/components` may import this module: the
 * token is a server concern.
 */

const COOKIE = SESSION_COOKIE;

export async function getSessionToken(): Promise<string | null> {
  const store = await cookies();
  return store.get(COOKIE)?.value ?? null;
}

export async function setSessionToken(token: string, expiresAt: string) {
  const store = await cookies();

  store.set(COOKIE, token, {
    httpOnly: true,
    secure: SESSION_COOKIE_SECURE,
    sameSite: "lax",
    path: "/",
    expires: new Date(expiresAt),
  });
}

export async function clearSessionToken() {
  const store = await cookies();
  store.delete(COOKIE);
}

/**
 * The challenge between the password and the code (ADR 0030).
 *
 * Two minutes, matching the server's own expiry: a cookie that outlived the
 * challenge would send somebody to a code prompt that cannot succeed.
 */
export async function setMfaChallenge(challenge: string) {
  const store = await cookies();

  store.set(MFA_CHALLENGE_COOKIE, challenge, {
    httpOnly: true,
    secure: SESSION_COOKIE_SECURE,
    sameSite: "lax",
    path: "/",
    maxAge: 120,
  });
}

export async function getMfaChallenge(): Promise<string | null> {
  const store = await cookies();

  return store.get(MFA_CHALLENGE_COOKIE)?.value ?? null;
}

export async function clearMfaChallenge() {
  const store = await cookies();
  store.delete(MFA_CHALLENGE_COOKIE);
}

/**
 * A single sign-on round trip in progress (ADR 0052): the binding this
 * browser will have to show, and where it was going.
 *
 * Ten minutes, the API's own limit for time spent at the identity provider.
 */
export async function setSsoBinding(binding: string, next: string) {
  const store = await cookies();

  store.set(SSO_BINDING_COOKIE, JSON.stringify({ binding, next }), {
    httpOnly: true,
    secure: SESSION_COOKIE_SECURE,
    sameSite: "lax",
    path: "/",
    maxAge: 600,
  });
}

export async function getSsoBinding(): Promise<{ binding: string; next: string } | null> {
  const store = await cookies();
  const raw = store.get(SSO_BINDING_COOKIE)?.value;

  if (!raw) return null;

  try {
    const parsed: unknown = JSON.parse(raw);

    if (
      typeof parsed === "object" &&
      parsed !== null &&
      "binding" in parsed &&
      "next" in parsed &&
      typeof parsed.binding === "string" &&
      typeof parsed.next === "string"
    ) {
      return { binding: parsed.binding, next: parsed.next };
    }
  } catch {
    // A cookie this app did not write, or one cut short. Either way it binds
    // nothing, and the sign-in starts again.
  }

  return null;
}

export async function clearSsoBinding() {
  const store = await cookies();
  store.delete(SSO_BINDING_COOKIE);
}

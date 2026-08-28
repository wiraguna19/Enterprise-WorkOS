import { cookies } from "next/headers";
import { SESSION_COOKIE, SESSION_COOKIE_SECURE } from "./session-cookie";

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

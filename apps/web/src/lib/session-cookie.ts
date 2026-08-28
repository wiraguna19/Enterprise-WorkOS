/**
 * The session cookie's name — shared by the server-side session helpers and by
 * the proxy, which must look for the exact same cookie.
 *
 * The `__Host-` prefix is a browser-enforced hardening measure: a cookie
 * carrying it is rejected unless it is `Secure`, path `/`, and has no `Domain`.
 * Development runs over plain http, where `Secure` cannot be set, so the prefix
 * would make the browser silently DROP the cookie — login appears to succeed
 * and the next request is anonymous again. The prefix is therefore tied to the
 * same condition as `secure` itself (docs/06 §1).
 */
export const SESSION_COOKIE_SECURE = process.env.NODE_ENV === "production";

export const SESSION_COOKIE = SESSION_COOKIE_SECURE
  ? "__Host-workos-session"
  : "workos-session";

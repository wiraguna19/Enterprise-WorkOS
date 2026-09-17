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

/**
 * The half-finished sign-in, between the password and the code (ADR 0030).
 *
 * A cookie rather than a value passed back to the form, for the same reason the
 * session token is one: nothing about authenticating belongs in the browser's
 * JavaScript heap. It is HttpOnly, it dies in two minutes with the challenge
 * inside it, and it is deleted the moment a code is accepted.
 */
export const MFA_CHALLENGE_COOKIE = SESSION_COOKIE_SECURE
  ? "__Host-workos-mfa"
  : "workos-mfa";

/**
 * Where to go after signing in — and where NOT to go (ADR 0032).
 *
 * `proxy.ts` has written `?next=<path>` on every redirect to the sign-in screen
 * since it was written, and nothing has ever read it. Somebody whose session
 * ends while they are reading a work item signs in and lands on the home page,
 * with the URL they actually wanted sitting in the address bar of the page that
 * sent them there. A write path with no read path, which is the defect this
 * phase keeps finding.
 *
 * It needs a function rather than a `?? "/"` because of the attack. A
 * destination taken from a URL and followed after authentication is an open
 * redirect: `/login?next=https://evil.example/login` sends somebody who has
 * just proved who they are to a page that looks exactly like the one they were
 * on. So only a path inside this app is allowed:
 *
 * - it must start with a single `/`. `//evil.example` is a protocol-relative
 *   URL, which a browser resolves as another origin, and `/\evil.example` is
 *   the same trick with the slash somebody forgot to reject;
 * - anything else — an absolute URL, a scheme, an empty value — falls back to
 *   the home page rather than being repaired, because a destination that had to
 *   be repaired is not a destination anybody asked for.
 */
export function safeNextPath(value: string | null | undefined): string {
  if (typeof value !== "string" || !value.startsWith("/")) return "/";

  if (value.startsWith("//") || value.startsWith("/\\")) return "/";

  // Control characters, which can hide the real target from somebody reading
  // the URL out loud.
  for (const character of value) {
    const code = character.codePointAt(0) ?? 0;

    if (code < 0x20 || code === 0x7f) return "/";
  }

  // The sign-in screen itself: coming back to /login after signing in is a
  // loop, and it is exactly what the proxy would write if a session ended
  // mid-redirect.
  if (value === "/login" || value.startsWith("/login?")) return "/";

  return value;
}

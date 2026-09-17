# ADR 0032 — Signing in takes you back where you were

- **Status:** accepted
- **Date:** 2026-09-17
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/07` §2, ADR 0012, ADR 0030

## Context

`proxy.ts` writes `?next=<path>` onto every redirect to the sign-in screen, and
has done since it was written. Nothing has ever read it.

So somebody whose session ends while they are reading a work item signs in and
lands on the home page, with the URL they actually wanted sitting in the address
bar of the page that sent them away. It is the same shape as the audit log
(ADR 0019), the session table (ADR 0023), the idle timeout (ADR 0029) and the
MFA columns (ADR 0030): a write path with no read path, written once and
believed ever since.

It surfaced while watching a development log — `GET /login?next=%2F` — during a
two-factor test, which is the only reason anybody looked.

## Decision

**`safeNextPath()` decides, and it is a function rather than a `?? "/"`.** A
destination taken from a URL and followed *after* authentication is an open
redirect: `/login?next=https://evil.example/login` sends somebody who has just
proved who they are to a page that can look exactly like the one they left. So
only a path inside this app is accepted:

- it must start with a single `/`. `//evil.example` is a protocol-relative URL,
  which a browser resolves as another origin, and `/\evil.example` is the same
  trick with the slash somebody forgot to reject;
- control characters are refused, because they hide the real target from anybody
  reading the URL;
- `/login` itself is refused, which is what the proxy would write if a session
  ended mid-redirect, and would otherwise be a loop;
- anything else falls back to the home page rather than being repaired. **A
  destination that had to be repaired is not a destination anybody asked for.**

**Validated twice — in the page and again in the action.** The hidden field the
server rendered a moment ago is still an input, and an input is never trusted
because of where it came from.

**The code prompt carries it too.** A sign-in interrupted by a second factor is
still the same sign-in (ADR 0030), and dropping the destination there would make
two-factor the reason somebody lands on the wrong page.

## Consequences

- The flow has a spec of its own, with a **fresh browser context**, because the
  rest of the suite deliberately reuses a saved session to stay inside the login
  rate limit (`e2e/support/auth.ts`) and therefore could never observe this. It
  signs in as Tono, whom no other spec uses, so it does not spend anybody else's
  five attempts.
- The open-redirect case is asserted as well as the happy path. A test that only
  proves the destination is honoured would pass just as well against an
  implementation that honours `https://evil.example` too.
- `proxy.ts` still writes the parameter for paths only, so nothing constructs an
  absolute destination today. The validation is not defending against this
  codebase; it is defending against the URL, which anybody can type.

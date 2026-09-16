# ADR 0023 — A session is something a person can see and end

- **Status:** accepted
- **Date:** 2026-09-16
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §1, `docs/10` Phase 7, ADR 0019, ADR 0022

## Context

`sessions` has stored an IP address, a user agent, a creation time and a
last-used time since Phase 1. Nothing has ever read them. Signing in writes a
row, signing out revokes it, a scheduled command deletes the dead ones — a
complete write path with no read path at all.

That is the shape ADR 0019 found in the audit log, and it is worse here. The
audit log answers questions asked after an incident; this table answers the
question somebody asks *during* one: **what else is signed in as me, and can I
stop it?** Until now the only answer this product could give was "change your
password and hope", because nothing invalidated the other sessions either.

`SessionModel::revoke()` has taken a `$reason` since Phase 1 and dropped it on
the floor. `PruneExpiredSessions` explains in its own docblock that revoked rows
are kept because "I was logged out — when, and by what?" is asked in the days
after, while the answer to "by what" was never stored.

## Decision

**Three endpoints, none of them gated by a permission.** List my sessions, end
one, end all the others. This is not authority over somebody — it is an account
looking at itself — and inventing `session.manage` would have put a fifth
meaningless key on the bill in `EveryPermissionMeansSomethingTest`. Every one is
scoped to the user in the request.

**A session that is not yours is a 404, never a 403.** A session id is a uuid
somebody could paste, and "that one exists but is not yours" confirms a guess
about another account. Same reasoning as the invitation endpoints in ADR 0017.

**The current session is marked, never hidden, and can be ended.** A list that
omitted the device you are holding would read as "somebody else is signed in
here". Ending it is signing out, and it is labelled as that.

**"End the others" keeps the one asking.** It is the button somebody presses
when they believe they have been compromised, and a version that signed them out
too would leave them unable to change their password — the very next thing that
should happen.

**Revocation takes effect within one request**, because `findToken()` already
rejects a revoked row. Nothing waits for a 30-day token to expire.

**The reason a session ended is stored.** A short constrained column, not free
text: the answers are a closed set — signed out, ended from another device, an
administrator revoked it, the account was erased — and a column that could hold
a sentence eventually would, in a table nobody reads for prose. A CHECK keeps
`revoked_at` and `revoked_reason` set together, so neither can be written alone.

**The user agent is shown raw.** Parsing them is a losing game with a long tail,
and "Chrome on a Mac" derived wrongly is worse than the string somebody can read
for themselves.

## Consequences

- The web page formats times on the SERVER and hands the client strings. A
  Server Component may give a Client Component values, never callables — the
  mistake that broke `/settings/notifications` on the day it shipped, with a
  runtime error on render that nothing in the type system sees.
- **The policy half of `docs/10`'s "session policy controls" is still owed.** An
  organization cannot yet say how long a session may live, force a shorter
  window, or require re-authentication for sensitive acts. The 30-day expiry is
  written in `AuthenticationService` and is the same for everybody. That needs a
  settings endpoint, which is where `organization.manage_settings` — one of the
  entries still on the permission bill — would finally mean something.
- **The CHECK found a defect in code no test ever ran.**
  `AuthenticationService::revokeAllSessions()` has existed since Phase 1,
  documented as "called on password change, MFA change, role change and
  membership revocation". It is called by nothing, none of those four flows
  exists, and it wrote `revoked_at` without a reason — so it would have violated
  the new constraint the first time anybody called it. It is corrected rather
  than deleted, and it is owed a caller: the password-change slice is the one
  that should have it.
- Ending a session does not notify the device that lost it. There is no mail
  layer in this product (ADR 0017) and a notification nobody receives is not a
  safeguard; what exists instead is the audit event, readable by an
  administrator.
- An erased person's sessions are deleted outright rather than revoked
  (ADR 0022), so they never appear here — the only case in the product where a
  session row is destroyed rather than ended.

# ADR 0029 — Idleness is measured, or it is not a policy

- **Status:** accepted
- **Date:** 2026-09-17
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §1, `docs/10` Phase 7, ADR 0023, ADR 0028

## Context

`docs/06`'s session table has said **"Idle timeout · 8 hours
(org-configurable)"** since Phase 1. Nothing in the product has ever measured
idleness. `sessions.last_used_at` is written by Sanctum on every authenticated
request and was read by exactly one thing — the session list, to print a date.

ADR 0028 closed the row below it and said this one was still fiction. It is the
same defect as a permission nothing consults, and it is worse for the same
reason `organization.view` was the worst entry on that bill: the claim is about
security, so the specification is a promise somebody may have repeated to a
customer.

Nothing had to be built to collect the signal. It has been recorded on every
request in the product for seven phases and thrown away.

## Decision

**Enforced in `findToken`, not in middleware.** That method already rejects
revoked and expired sessions, which is how offboarding takes effect within one
request. An idle session is the same kind of answer — this credential no longer
authenticates anybody — and putting it anywhere else would leave a second
opinion about what a valid session is, which is this codebase's
two-layers-disagree defect waiting to happen.

**The organization's window is carried back by the same query.** A LEFT JOIN on
`organizations`, not a second lookup: this runs on every authenticated request
in the product, and a query added to every page in exchange for a setting most
organizations leave off is a bad trade. Left, because `sessions.organization_id`
is nullable and a session bound to no organization has no policy to answer to.

**An idle session is ENDED, not merely turned away.** It is revoked on the spot
with `revoked_reason = 'idle_timeout'`. A row that authenticates nobody while
still reading as live in Settings → Signed in is the product lying about the one
question that screen exists to answer, and "signed out for inactivity" is
precisely the thing somebody asks about the next morning — which is what the
reason column was added for (ADR 0023).

**Idleness is measured from the last request, falling back to sign-in.** Age is
not idleness: a twenty-day-old session used a minute ago belongs to somebody who
never stops working, and signing them out would be the product punishing use. A
session issued and never used falls back to `created_at`, so the one session
nobody ever touched is not the one session that never ages out.

**NULL means off, and NULL is the default — including for every organization
that already exists.** The alternative is a migration that signs out a company
overnight to satisfy a number in a document nobody read. That is not a default,
it is an incident. Switching it on is a decision somebody makes on a screen,
and the screen says what it will do before they press the button.

**Bounded 5 minutes to 7 days by a CHECK**, like the lifetime beside it. Below
five minutes the product signs people out while they read; past seven days the
absolute lifetime is the thing actually ending the session, and a control that
cannot bite is worse than no control.

**Absent and null mean different things in the PATCH.** Absent leaves the
setting alone; null switches it off. A request body that read a missing key as
"off" would turn the idle timeout off every time somebody changed the lifetime
in the field above it.

## Consequences

- **Tightening the window applies retroactively for free**, which is the
  opposite of the lifetime. The lifetime is stamped on the row at sign-in, so
  ADR 0028 had to clamp existing sessions explicitly; idleness is evaluated at
  the door on every request, so a shorter window bites on the next one. The
  screen says so, including the case where the person setting it has been
  reading the page long enough to sign themselves out.
- The refusal costs one extra UPDATE, on the request that gets refused, once
  per session. The successful path is the same single query it has always been.
- **`last_used_at` is now load-bearing.** It was decoration; it is now the
  thing that decides whether somebody is signed in. Sanctum writes it, which
  means a future decision to stop using Sanctum's guard has to carry this with
  it — there is a test that would catch the loss, which is the point of writing
  it down here as well.
- The session list does not mark sessions that are about to go idle. Every
  session on that screen belongs to the person reading it, and the request that
  drew the page is itself proof that the current one is not idle; the others
  are refused and ended the moment they are used.
- Still owed from `docs/10`'s security column: enforced MFA, SSO/SAML, and
  re-authentication for sensitive acts. That last one is the nearest, and it is
  a different question from this — "prove it again before this act", not
  "prove it again because time passed".

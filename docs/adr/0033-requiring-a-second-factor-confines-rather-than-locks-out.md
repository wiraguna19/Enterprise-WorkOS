# ADR 0033 — Requiring a second factor confines rather than locks out

- **Status:** accepted
- **Date:** 2026-09-17
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §1, `docs/10` Phase 7, ADR 0029, ADR 0030, ADR 0031

## Context

`docs/06` has said TOTP is "enforceable per organization from Phase 7" since
Phase 1. ADR 0030 built the mechanism and left the policy owed in as many words.
This is the policy.

The mechanism was the easy half. The decision is what happens to the people who
have not enrolled at the moment somebody flips the switch — including the person
who flips it, who has no second factor either right then.

## Decision

**Confinement, not a locked door.** Somebody without a factor still signs in.
The session they get can do exactly four things: say who they are, sign out,
start enrolment, finish it. Everything else answers 403 `auth.mfa_required`.

Refusing the sign-in would be simpler and wrong in both directions at once: it
locks out every person who had no warning, on a switch somebody else flipped,
and it locks out the administrator who flipped it. **A policy whose first act is
to lock its own author out is a policy nobody turns on**, and a security control
nobody turns on protects nothing.

**Evaluated per request**, like the idle timeout (ADR 0029), so the switch
reaches sessions that already exist without ending any of them. Nobody is signed
out; everybody is asked. The count of people it applies to is shown before the
button is pressed and again after — the difference between a setting and a
consequence, and here the consequence lands on other people's afternoons.

**The allow-list is by route NAME, not by path.** A path list drifts the first
time somebody moves a route, and it drifts silently, in the direction of letting
more through.

**Turning a factor off is refused while the organization requires one**, rather
than allowed and then confined a millisecond later. A loop is not a feature, and
the refusal says which rule refused.

**No exceptions, including for administrators.** An administrator who turns the
requirement on without a factor is confined by it and cannot turn it back off
until they enrol. That is the honest consequence of a policy with no exceptions,
and the way out takes thirty seconds and is the thing they were asking of
everybody else.

**The web app redirects rather than letting people walk into 403s.** `/auth/me`
carries `requires_second_factor`, and the app layout sends a confined person to
the enrolment screen. The server is still the authority — the middleware refuses
whatever any client does — but a person should meet a form, not an error on
every link they press.

**One redirect, in `api()`, and not in the layout.** The first attempt put it in
the app layout, which cannot ask which page is rendering inside it — so it
needed `proxy.ts` to forward the path as a header, and when that header did not
arrive the layout redirected the enrolment screen to itself: a blank page and a
log filling with 200s. The header, the fallback for its absence and the layout
check are all gone. Every refused call already carries the person to enrolment
and the enrolment screen makes no refused call, so the mechanism that cannot
loop is the only one left. **Two mechanisms, one of them half-working, are worse
than one.**

## Consequences

- **A page's own data call races the layout anyway.** The first time the policy
  was switched on for real, `/settings/sessions` threw an unhandled
  `ApiRequestError` into the log before any redirect landed: a layout and the
  page inside it render together, not in order. That is half of why the redirect
  belongs in `api()`; the loop above is the other half.
- **The confined shell has no navigation at all**, and this took two passes.
  The first removed only the navigation's DATA — teams, counters, unread —
  because the API refuses those, and left the links themselves in place. A
  development log then showed what that means in practice: nine links pressed,
  nine bounces back to the enrolment screen. A sidebar whose every door leads to
  the same room lies about where somebody can go, and making them discover that
  one link at a time is the product spending their afternoon to keep its own
  furniture. The sidebar, the bottom bar, the notification bell and the command
  palette are all removed for a confined session; the account menu stays,
  because signing out is one of the four things that session may do.
- `requiresSecondFactor()` joins `SessionPolicy` rather than getting an
  interface of its own. Enrolling a factor belongs to a person and is not
  Organization's business; whether a SESSION here may act without one is
  precisely what that contract is for.
- **The policy costs no query.** The first version injected the reader and
  asked the `organizations` table on every request; six query budgets failed
  inside a minute, which is exactly what they are for. The answer now rides on
  the join `SessionModel::findToken()` already makes for the idle window
  (ADR 0029), and `/auth/me` — the call every page render makes — reads it off
  the same session row. Raising the budgets would have bought a second read of a
  row the request had already touched.
- An unreadable organization answers false: a policy that cannot be read must
  not lock a tenant out of itself.
- **What this does not do:** grace periods ("you have seven days"), exemptions
  for named people, or anything about API tokens, because the product has no
  API tokens yet. A grace period is a second clock and a second set of states;
  it can be added the day somebody asks for it, and the confinement above is
  what a grace period would expire into anyway.
- Still owed from `docs/10`'s security column: SSO/SAML, and re-authentication
  before sensitive acts.

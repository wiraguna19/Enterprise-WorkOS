# ADR 0028 — An organization decides how long a session lives

- **Status:** accepted
- **Date:** 2026-09-17
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §1, `docs/10` Phase 7, ADR 0023

## Context

ADR 0023 shipped the half of *session policy controls* that a person can see:
what is signed in as me, ending one, ending the others. It recorded the other
half as owed in the same breath — an organization cannot say anything about how
long any of it lasts. `now()->addDays(30)` has been written in
`AuthenticationService` since Phase 1 and is the same number for a contractor's
borrowed laptop and a finance team's workstation.

Two permissions sat on `EveryPermissionMeansSomethingTest`'s bill for this.
`organization.manage_settings` — "organization-wide settings have no endpoint" —
and `organization.view`, which was the worst entry on the list for a different
reason: the web app's nav has hidden the Settings entry behind it since Phase 1
while no route, policy or service on the server had ever asked. **An interface
that hides a control on a permission the API has never heard of is a product
that looks enforced and is not**, and that is the least visible defect in the
catalogue, because every screenshot of it is correct.

## Decision

**A column on `organizations`, not the `settings` jsonb.** That blob has been
on the table since Phase 1 and has never been read. A blob cannot be
constrained: `{"session_lifetime_days": "7"}` is a string, `{"session_lifetime_dys": 7}`
is a typo, and Postgres accepts both — the defect surfaces as somebody being
signed out at the wrong moment, weeks later. The bounds ARE the slice, so they
live where nothing routes around them.

**Bounded 1 to 90 by a CHECK.** Zero is not a policy, it is a lockout. A
year-long session is indistinguishable from no expiry at all. The form request
repeats the bounds to produce a readable refusal rather than a 500, but it is
not the enforcement: a form request protects one door, and this value is also
written by seeders, by tinker, and by whatever provisioning grows later.

**Lowering clamps sessions that already exist.** This is the whole reason the
slice is not a one-line change to a constant. An administrator who shortens the
window to a day because a laptop went missing has, under the naive version,
changed nothing whatever about that laptop — it keeps the thirty days it was
born with while the screen says one. Every live session past the new limit is
pulled back to it, inside the same request, because the answer must be true by
the time the page comes back and not by the time cron next runs.

**Raising does not extend.** A session issued under a seven-day promise was
reviewed, if it was reviewed at all, as a seven-day session. Stretching it to
ninety because somebody relaxed a setting hands out access nobody looked at.
The new number governs the next sign-in — the moment the person proves who they
are again.

**Clamping is not revoking.** A shortened session stays valid until its new
expiry. "Adjust the window" and "sign my whole company out right now" are
different acts with different blast radii, and a setting that quietly did the
second would be used once and never again.

**The clamp skips sessions that have already expired.** Without that filter the
statement pushes every dead row in the organization out to the new limit: a
policy change that signs people back in, and revives rows
`PruneExpiredSessions` is on its way to delete.

**Identity reads the policy through a Platform contract.** Identity issues the
session and must know the window; Organization owns the setting; the module
graph runs Organization → Identity and never back (`docs/04` §3). Same seam as
`OrganizationDirectory`, and a separate interface rather than a fifth method on
it — that one reads a name for a payload, this one decides whether somebody is
still signed in tomorrow. The reader is asked DURING login, before the tenant
resolver has run, and works there only because `organizations` is the tenant
rather than a tenant-scoped table: a scoped read would answer the default for
every login in the product and look perfectly correct in any test that signs in
first.

**Two endpoints, two permissions.** `GET /organization/settings` behind
`organization.view`, `PATCH /organization/settings/session-policy` behind
`organization.manage_settings`. Seeing that sessions here last seven days is
part of understanding the place you work; deciding it is not.

**The number of shortened sessions is reported to whoever pressed the button.**
It is the difference between a setting and a consequence, and the alternative
is learning it from the people who were signed out.

## Consequences

- Two entries leave the permission bill. `organization.update` stays: nothing
  in the product changes an organization's name or slug, and the settings page
  says so rather than showing a disabled field that implies otherwise.
- **The web page is gated twice** — the nav entry and the page itself — because
  a nav entry whose target refuses reads as a broken product, and a page whose
  target does not reads as an unbuilt one. `notFound`, not a 403 screen: the
  existence of a screen is itself something not everybody is owed.
- The form offers presets, not a number box. Every value in range is legal and
  the API takes any of them, but offering 47 as readily as 30 invites a choice
  nobody can justify.
- `docs/06`'s session table said "30 days with sliding refresh" and now says
  what the product does. The **idle timeout** in the row above it — 8 hours,
  "org-configurable" — is still fiction: nothing measures idleness. It is the
  next thing this setting's screen should grow, and it is a different mechanism,
  not a different number.
- **The browser cookie follows for free on the way in, and not on the way
  back.** `setSessionToken` already writes the cookie with the `expires_at` the
  API returns, so a seven-day organization gets a seven-day cookie without a
  line of web code. A CLAMPED session is the other direction: the cookie in a
  browser that is not making a request cannot be shortened, so it outlives the
  session it names and the next call gets a 401 — which the app already answers
  by sending the person to the login screen (`isSignedOut`). That is the correct
  outcome and worth stating, because the alternative reading is that clamping
  did not work.
- Still owed from `docs/10`'s security column: enforced MFA, SSO/SAML,
  re-authentication for sensitive acts. All three will gate on
  `organization.manage_settings`, which is why this slice did not invent a key
  of its own.

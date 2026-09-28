# ADR 0050 — Switching organization is a new session

- **Status:** accepted
- **Date:** 2026-09-28
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §1, `docs/08` §1, `docs/10` Phase 7, ADR 0033, ADR 0034, ADR 0049

## Context

docs/06 §1 has said since Phase 1:

> A user with several memberships selects an active organization after login;
> the choice is bound to the session row (`sessions.organization_id`), not to a
> header the client can change. **Switching organizations issues a new token.**

Nothing selected and nothing switched. `resolveMembership()` picked the oldest
active membership at sign-in, and a person in two organizations could reach
the second only by signing out and passing `organization_id` to a login form
that never offered it. docs/08 §1 draws the switcher in the header; the header
printed the organization's name beside a comment calling it the switcher.

The seed made the gap invisible: nobody in it belonged to two organizations.

## Decision

**A switch is a new session in the other organization, and the old one ends.**

- `POST /auth/organization {organization_id}` looks up the membership by that
  organization AND this user. Naming one you do not belong to answers exactly
  as naming one that does not exist (`auth.no_active_membership`), and leaves
  the asking session untouched.
- The new session is issued by the same `issueSession()` a sign-in uses, so it
  lives by the TARGET organization's lifetime (ADR 0028) and answers to ITS
  second-factor requirement (ADR 0033). The old one is revoked with the reason
  `switched_organization`, after the new one exists — a failure leaves the
  person where they were, not signed out of both.
- **Never a re-scope of the existing row.** A session whose tenant could change
  is a session the client could point anywhere; the tenant stays a property of
  the row, written once.
- **The re-authentication window stays shut** (ADR 0034). Signing in proves the
  password; a click proves nothing, and a window opened by one would let a
  borrowed laptop erase somebody in the second tenant.
- **Recorded in both organizations**: `auth.organization_switched_out` in the
  one being left, `auth.organization_switched_in` in the one being entered.
  Each organization's audit view is its own, and a switch only one side could
  see would be half a record.
- **A confined session may leave.** `RequireSecondFactor` lets
  `auth.organizations` and `auth.organization.switch` through: one
  organization's requirement must not become a lockout from every other.
- **An API token cannot switch.** The routes are under `auth.`, which
  `LimitApiTokens` refuses (ADR 0049); a token stays in the organization it was
  made in.

**The switcher lives in the account menu**, and its list is fetched when the
menu opens — not on every render. Most people belong to one organization; with
one, the section does not appear.

The seed gains the one person who needs it: **Rina belongs to Globex as an
employee**, joined after her Acme membership, so signing in without choosing
still lands in Acme.

## Consequences

- docs/06 §1's sentence is now true.
- Everything cached for the organization being left is revalidated and the
  person lands on Home, not on the same path — which may name something that
  does not exist in the other organization.

Still owed:

- **Remembering the last organization.** Sign-in still chooses the oldest
  membership; a person who works mostly in their second organization switches
  every morning.
- **Row-Level Security**, the other half of docs/10's tenancy line, is its own
  slice: it touches every query, job and migration, and it should not ride in
  on a UI change.

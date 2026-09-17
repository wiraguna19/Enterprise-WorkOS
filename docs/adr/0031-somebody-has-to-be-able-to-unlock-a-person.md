# ADR 0031 — Somebody has to be able to unlock a person

- **Status:** accepted
- **Date:** 2026-09-17
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §1, ADR 0022, ADR 0023, ADR 0030

## Context

Two-factor authentication shipped in ADR 0030. Within an hour of it existing,
somebody enrolled, lost the authenticator entry and the recovery codes on the
same afternoon, and the product's answer was an `UPDATE` in `psql`.

That is not an answer. In a real organization the answer is a help desk, and a
help desk that has to ask an engineer with database credentials is a help desk
that does not exist. The account in question was the **organization
administrator** — the most privileged role in the product could not help itself,
and could not have helped anybody else either.

The same afternoon produced a second, quieter finding: re-enrolling leaves the
authenticator app holding two entries with identical labels, one of them dead.
From the outside that reads as "the app keeps giving me wrong codes", which is
how a working feature becomes a support ticket.

## Decision

**An administrator may take somebody else's second factor off**, through
`DELETE /people/{membership}/mfa`, gated on `person.deactivate`.

**That permission, not a new one.** It already means "I may take this person's
access away", and a help desk trusted to end somebody's access entirely but not
to unlock them is a split nobody could defend. A new key would also have to be
granted twice — in the seed and by migration to roles that already exist — and
this codebase has shipped a permission granted to nobody before, where the
feature simply did not exist for the role it was written for.

**Never on yourself.** This is the important half. The self-service path asks
for the password (ADR 0030) precisely because removing a factor is what somebody
does with a laptop left unlocked; an administrator who could use this endpoint
on their own membership would have walked around that with one click. The policy
refuses self exactly as erasure does.

**The audit event is written into every organization the person belongs to.** A
factor belongs to a person, not to a membership, so an administrator of one
tenant is removing protection that another tenant also relied on. Refusing in
that case would leave people working across two organizations — the ones with
the most to lose — with no way back in at all, so the act is allowed and the
other organization gets the truth immediately, in the log its administrators
already read. The audit view is tenant-scoped; an event written only where the
administrator stands would be invisible exactly where it matters, which is the
trap the login event fell into for six phases.

**The person's sessions are left alone.** They did nothing wrong. Signing them
out of everything on the day they are already locked out would be the product
kicking somebody who is down. What an attacker gains from this act is nothing
they did not need anyway — the password is still required — and unlike quietly
knowing a password, this act is attributed and logged.

**Whether a factor is on is sent only to somebody who may take it off.**
`PersonResource` says in its own docblock that identity-level state does not
belong in a tenant-local view, and this is the one deliberate exception: the
alternative is an Unlock control that is always offered and refuses half the
time, which teaches people to press it and see.

**The provisioning label carries the date of enrolment.** Authenticator apps do
not replace an entry whose label matches; they add a second one. `Work OS:
you@example.com (17 Sep 2026)` is enough to tell a live entry from the dead one
beside it, and to know which to delete.

## Consequences

- **The product now has a lockout story that does not involve a database
  client**, for everybody except the last remaining administrator of a
  single-organization tenant who loses their own phone and codes. That person is
  still stuck, and honestly so: the alternative is an account that can unlock
  itself, which is not a second factor.
- The control states what it costs before it is pressed — no sign-out, no
  password change, password alone gets them in again. An administrator who
  believes this is harmless will use it on a phone call from somebody they have
  not identified, which is the actual attack this endpoint is exposed to, and
  the copy is the only defence the product can offer against it.
- `person.deactivate` now means two things. That is a widening, recorded here
  rather than discovered later: anybody holding it can both end access and
  restore it.
- Still owed from `docs/10`'s security column: SSO/SAML, enforced MFA per
  organization, and re-authentication before sensitive acts. This endpoint is a
  candidate for the last of those the day it exists.

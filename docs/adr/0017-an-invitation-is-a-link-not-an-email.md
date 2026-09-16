# ADR 0017 — An invitation is a link the product hands over, not an email it sends

- **Status:** accepted
- **Date:** 2026-09-16
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06-auth-and-authorization.md` §1,
  `docs/11-testing-strategy.md` §4 flow 2, ADR 0016

## Context

`person.invite` has been in the permission catalogue since Phase 1, granted to
managers and org admins, with **no endpoint behind it for seven phases** — the
longest-running instance of this codebase's oldest defect class. The
`invitations` table was written in the same migration, with a token digest, an
expiry, a revocation column and a partial unique index over pending rows.
Everything was there except the feature, and flow 2 asserted its absence so
that the day somebody built it half-way, a test would say so.

Building it surfaced the real decision: **there is no mail layer in this
product.** No Mailable, no queue consumer for one, nothing but Mailpit in the
compose file. An invitation feature that assumes email is a mail feature with an
invitation attached.

## Decision

**The invitation's link is returned once, to the administrator who created it,
to pass on however they already talk to the person.** The screen says so in as
many words — "Nothing was emailed" — because implying an email is on its way is
the difference between a limitation and a lie.

This is not a placeholder for email. It is the honest version of what the
product can do today, it is testable end to end, and email becomes a DELIVERY
CHANNEL on top of it rather than a rewrite of it: the token, the expiry, the
revocation and the accept flow do not change when a mailer arrives.

**Only `sha256(token)` is stored.** A token that creates an account is a
credential, and credentials are digests here. The raw value exists in exactly
one response and then nowhere, so "they lost the link" is answered by revoking
and reissuing — the same answer every password reset gives.

**Accepting is public, and has two branches.** With no user for that address,
one is created with the password chosen on the form. **With an existing user,
the password is not touched** — this is an invitation to join an organization,
not a password reset, and a flow that quietly reset it would be an account
takeover available to anyone who could get an invitation sent to a colleague's
address. The membership row is written with the query builder rather than the
model, because `BelongsToOrganization` fills `organization_id` from the tenant
CONTEXT and there is no context on a public route; the organization comes from
the invitation row, which is the only authority that exists at that point.

**Wrong, expired, revoked and already-accepted are indistinguishable.** All
four answer 404 on preview and the same refusal on accept. Any difference is an
oracle for guessing tokens.

**The two public endpoints carry their own rate limiter.** Login's is keyed by
`ip|email`; these requests carry no email, which would collapse every caller
behind one NAT onto the same five attempts per quarter hour. `invitation` is
keyed by token AND address: guessing tokens from one address is what it is for,
somebody retyping their own link is not.

**The preview returns the organization's name and the invited address, and
nothing else.** Not the inviter, not the role, not anything about who else is
here — it is readable by anyone holding a string.

## Consequences

- **Flow 2 is complete for the first time**: department → team → invite person →
  assign role, the last step being a scoped grant (ADR 0016) on the team the
  flow just built. The invitation half runs in two browser contexts, because
  the second half is performed by somebody with no session.
- **A password is chosen in exactly one place in this product**, so that is the
  only place that can refuse a bad one: twelve characters minimum.
  `uncompromised()` is deliberately absent — it calls the k-anonymity range API
  from inside the request and fails open on a timeout, and a check that is
  sometimes performed is a check nobody can rely on. Making it dependable is a
  decision about where that call lives.
- **Issuing and accepting are audit events, not activity ones.** "Who let them
  in" is a security question; `audit_logs` is where the answer belongs.
- An expired invitation is kept and listed as expired rather than hidden. "I
  sent that a fortnight ago and heard nothing" is only answerable if the row is
  still there.
- The role on an invitation is optional. Somebody can be invited before anyone
  has decided what they will do, and a required role would push a permanent
  choice into the most hurried moment.

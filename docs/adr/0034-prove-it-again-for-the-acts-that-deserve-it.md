# ADR 0034 — "Prove it again", for the acts that deserve it

- **Status:** accepted
- **Date:** 2026-09-18
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §1, `docs/10` Phase 7, ADR 0022, ADR 0028, ADR 0029, ADR 0031, ADR 0033

## Context

A session in this product can last ninety days (ADR 0028), and an organization's
idle window can be hours (ADR 0029). Both are right for reading work and moving a
card. Neither is an answer to the oldest attack in an office: somebody sitting
down at a laptop its owner left unlocked.

Phase 7 added three acts where that matters. Erasing a person is irreversible
(ADR 0022). Taking somebody else's second factor off removes the protection on
an account that is not yours (ADR 0031). Changing the organization's policy
decides what everybody must do before their next request (ADR 0028, ADR 0033).
Each is a single click behind a permission somebody holds all day.

## Decision

**One timestamp on the session**, `reauthenticated_at`, and one question asked
by the acts that care: was the password proved inside the last fifteen minutes?

**Not a permission, and not a middleware.** A permission says who may; this says
how recently they proved they are still that who. A middleware would have to be
attached route by route regardless, and the list is short enough to read in one
line of a service's docblock.

**Signing in counts.** The window opens at login, because signing in IS proving
yourself, and asking for the password again a second later teaches people that
the prompt means nothing.

**Fifteen minutes, because the unit is a task, not a session.** An administrator
offboarding three people types their password once; somebody who went to lunch
finds the door shut. A shorter window makes the prompt a reflex, and a reflex is
exactly what makes a person type their password into whatever asks.

**403, never 401.** The session is valid and nothing about it is in question. A
401 tells every client in the world to throw the session away and send somebody
back to the sign-in screen — the opposite of "confirm one thing and carry on".

**It refuses by throwing.** A caller who forgets to check a boolean has silently
skipped the safeguard, and this codebase has met that shape often enough to know
how it ends.

**The confirmation belongs to one session.** Proving the password on a laptop
does not open the window on a phone. The threat is a second device somebody else
is holding, so a confirmation that crossed sessions would be a safeguard that
protects the attacker's session too.

**The prompt appears where the refusal happened, and finishes the job.** The
control that was refused grows a password field underneath it and carries out
the original act once the password is accepted. A refusal that says "confirm
your password" and leaves somebody to find the confirmation elsewhere is how a
safeguard becomes the thing people route around. The prompt also names what it
is protecting — "confirm your password to erase Tono Hartono" — because a
password box with no subject is indistinguishable from a phishing page.

**Only the password, not the second factor.** The factor was proved when the
session began and cannot be replayed (ADR 0030). What this tests is that the
person at the keyboard still knows the password, which is precisely what an
unlocked laptop does not establish.

## Consequences

- `describeApiError` returns the error CODE as well as the sentence. Until now
  every refusal meant the same thing to the interface — red text — and this is
  the first one a screen answers with a form.
- **Existing sessions are backfilled from `created_at`.** A session that signed
  in an hour ago did prove itself an hour ago, and pretending otherwise would
  greet everybody with a password prompt on the morning this deploys — which is
  how a good safeguard teaches people to type their password into anything that
  asks.
- The list of guarded acts is deliberately short: erase a person, remove
  somebody else's second factor, change the session policy, change the MFA
  policy. A product that asked before every write is a product whose prompts
  nobody reads. Role grants and denials were considered and left out — they are
  reversible, and they already leave an attributed trail.
- `REAUTH_CODE` lives in `lib/api.ts` rather than beside the Server Action that
  uses it: a `"use server"` module may export only async functions, so a
  constant in one is a build error.
- Still owed from `docs/10`'s security column: SSO/SAML, and nothing else.

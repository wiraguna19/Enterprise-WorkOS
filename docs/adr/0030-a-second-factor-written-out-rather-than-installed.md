# ADR 0030 — A second factor, written out rather than installed

- **Status:** accepted
- **Date:** 2026-09-17
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §1, `docs/10` Phase 7, ADR 0012, ADR 0022, ADR 0023, ADR 0027

## Context

`users` has carried `mfa_secret_encrypted`, `mfa_enabled_at` and
`mfa_recovery_codes` since Phase 1. Nothing has ever written any of them.
`/auth/me` has reported `mfa_enabled` to every client for seven phases — always
false, always confidently. `docs/06` says TOTP is "available from Phase 2".
`PersonErasure` scrubs all three columns (ADR 0022), and
`AuthenticationService::revokeAllSessions` has claimed since Phase 1 that it is
called "on password change, MFA change, role change and membership revocation"
while being called by nothing.

That is a feature the product believed in at four separate layers and did not
have. It is the same shape as the audit log (ADR 0019), the session table
(ADR 0023) and the idle timeout (ADR 0029), and it is the largest of them.

## Decision

**TOTP is written out, not installed.** RFC 6238 is forty lines: base32, an
HMAC, a truncation, a window. A dependency would be more code in `vendor` than
`Totp.php` contains, and the parts that actually decide whether this is safe —
the comparison, the drift window, what happens on reuse — would still have to
be understood by whoever reads this file. The same argument as the six icons in
ADR 0027, with one difference that matters: this is authentication, so the
reason it is safe to write out is that it is **verifiable**. `TotpTest` runs the
RFC's own appendix B vectors, not vectors this implementation produced. An
implementation that agrees with the RFC at those six moments agrees with every
authenticator app in the world.

**Enrolment is two steps, and the two columns were always for this.** The
secret is stored with `mfa_enabled_at` still null — pending — and only a correct
code from the app holding it turns the factor on. A one-step version locks out
everybody whose QR failed to scan, whose typing slipped, or whose phone clock is
wrong, and it does so at their next sign-in rather than while they are looking
at the screen.

**A code is spent when it is used — and what is remembered is the period the
CODE belongs to, not the period it was accepted in.** `mfa_last_counter` is the
one column this feature needed and did not have. The first version stored the
wrong one of those two numbers, and somebody found what that costs within an
hour of the feature existing: a code used at the end of one period is still
inside the drift window at the start of the next, where the stored counter has
already moved past it, so the same six digits signed in twice. `Totp::match()`
answers with the counter rather than a yes, which is what makes the rule hold
for the whole of a code's life. Without it a valid code stays valid for up to
ninety seconds across the drift window, so a code read over a shoulder — or
captured by a phishing page a moment earlier — signs in a second time. The cost
is real and is stated in the interface rather than hidden: a second sign-in
inside the same thirty seconds has to wait for the next code, and the message
says so instead of calling the code wrong. Confirming enrolment spends its code
too, so the six digits somebody just read aloud on a screen-sharing call are not
a sign-in.

**The login answers with a challenge, not a token.** A correct password for an
enrolled account produces a short-lived, encrypted challenge naming the user and
the organization — not a session. It is encrypted with the application key
rather than stored in a table: a row per attempt is something to write, index,
prune and reason about for a value that is meaningless after two minutes, and
Laravel's encrypter is authenticated, so an edited challenge does not decrypt at
all. The membership is re-checked when the challenge is opened rather than
trusted from inside it — two minutes is long enough for somebody to be
offboarded between the password and the code, and that is precisely the person
this feature exists to keep out.

**The challenge never reaches the browser's JavaScript.** It goes into an
HttpOnly cookie for two minutes, exactly like the session token it is half of
(ADR 0012). The code form reads it from there, so a page left open past its
expiry fails as "expired" rather than posting something the server refuses for
reasons the person cannot see.

**Recovery codes are hashed with SHA-256, not bcrypt.** They are twelve random
characters this product generated, not a password somebody chose: there is
nothing to brute force, so a slow hash buys nothing and costs ten comparisons on
every challenge. They are spent by removal, not marked as used — a spent code
left in the row is a code somebody can try again.

**Turning the factor OFF needs the password, and so does replacing the recovery
codes; turning it ON does not.** Removing a factor makes the account weaker and
ten new codes are ten new ways in — both are what somebody does with a laptop
left unlocked, so both ask for something the person would have to know rather
than merely have. Turning it on asks for nothing extra, because the worst an
intruder can do with it is lock themselves in beside a password the owner can
still change.

**Both changes end every other session**, with the reason `mfa_changed`. This is
the caller `revokeAllSessions` was documented as having since Phase 1 and never
had. Not this session, though: signing somebody out of the page they just
enrolled on is the product punishing the right act.

**No permission gates any of it**, like the session endpoints in ADR 0023. This
is an account deciding about itself, and an organization-wide key would mean an
administrator could be refused their own security settings.

## Consequences

- **`docs/06` stops claiming Phase 2.** Two of the claims in that section were
  fiction when this slice started; this closes the last of the three.
- **Enforced MFA per organization is now buildable and is not built.**
  `docs/10`'s "enforced MFA policy" means an organization requiring this of
  everybody, which is a different decision with a migration of its own (what
  happens to people who are already signed in, and to somebody who cannot enrol
  today). It will gate on `organization.manage_settings` beside the session
  policy, and it is the next slice on that screen.
- **The login flow has a second shape now.** `login()` returns a discriminated
  pair — `mfa_required` true with a challenge, or false with a session — rather
  than a token that is sometimes missing, so neither the controller nor the web
  app can forget to look.
- **A Server Action argument is printed in the development log.** The first
  real use of the enrolment screen put `confirmEnrolment("685123")` and
  `disableTwoFactor("password")` into the terminal — a live one-time code and
  somebody's actual password, in a dev log, a CI transcript, and any screen
  share that happens to be running. Next.js logs plain arguments verbatim and
  logs a `FormData` argument as `{}`, which is why the login form on the other
  side of this same feature never leaked anything. Both actions take `FormData`
  now. Worth recording as a rule rather than a fix: **a secret crosses the
  Server Action boundary as `FormData`, never as an argument.**
- **Losing the codes is a supported event now, not a lesson.** The screen that
  shows them once shipped without a copy button, without a download, and with
  copy telling anybody who lost their list to turn the factor off and set it up
  again — which leaves the account with no second factor for as long as it takes
  to re-scan a QR code, to solve a problem that was never about the factor.
  `POST /auth/mfa/recovery-codes` replaces the list in place, behind the same
  password as turning the factor off, and leaves the sessions alone because
  nothing about the account's factors has changed. Found within an hour of the
  feature existing, by somebody losing their own codes.
- The web app gained one dependency, `qrcode.react`, which has none of its own
  and renders an SVG. The alternative was asking people to type a 32-character
  secret, which is the kind of decision that makes a security feature optional
  in practice.
- **What is deliberately not here:** trusted devices ("don't ask again on this
  computer"), WebAuthn, and re-authentication before sensitive acts. The first
  two are features; the third is the remaining item in `docs/10`'s security
  column and is a different question from this one — "prove it again before this
  act", not "prove it again because you are signing in".

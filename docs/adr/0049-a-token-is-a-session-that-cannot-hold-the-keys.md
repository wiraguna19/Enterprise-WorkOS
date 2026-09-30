# ADR 0049 — A token is a session that cannot hold the keys

- **Status:** accepted
- **Date:** 2026-09-28
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §1, `docs/10` Phase 7, ADR 0023, ADR 0028, ADR 0029, ADR 0033, ADR 0034, ADR 0048

## Context

docs/10 promises "public API tokens". Until now the only way to call the API
was a browser sign-in: the token lives in an HttpOnly cookie on the Next.js
server and never reaches a script. Webhooks (ADR 0048) made the gap visible —
a receiver that gets `workflow.rule_matched` has an id and a reference and no
way to ask for more.

The obvious build is a second table of tokens with a second lookup. Every
property that makes a session safe would then have to be rebuilt beside it:
the digest-only lookup, the membership re-checked on every request (docs/06 §1
— the reason sessions are opaque), the second-factor confinement (ADR 0033),
the revoke-all path. The copy is always the weaker door.

## Decision

**A token is a `sessions` row with `kind = 'api_token'`.** It is resolved by
the same `findToken()`, checked by the same `ResolveTenant` and
`RequireSecondFactor`, and it acts as the person who made it, in the
organization they made it in, with exactly their permissions — never more.

What differs is enforced where it applies, and each difference is a decision:

| | Browser session | API token |
|---|---|---|
| Idle timeout (ADR 0029) | applies | **never** — a script may run weekly; its own expiry bounds the risk |
| Lifetime | the organization's (ADR 0028), clamped when lowered | **chosen when made: 30, 90 or 365 days**; never clamped by the browser policy |
| Re-authentication (ADR 0034) | opens for 15 minutes | **never opens** — `reauthenticated_at` stays null, and the route is refused |
| `auth.*` routes | all | **only `auth.me`** — no sessions, second factor, re-authentication |
| `api_tokens.*` routes | all | **none** — a leaked token cannot mint another |
| Writes | `*` | **only if made `read_write`**; `read` refuses anything but GET/HEAD |
| Listed under Signed in | yes | **no** — its own screen, Settings → API tokens |

The route refusals are `LimitApiTokens`, by route NAME and by PREFIX: a
credential route added under `auth.` later is refused to tokens until somebody
decides otherwise. Over-refusing is the safe direction — an integration that
needs a route says so; a token that can reach one says nothing.

**Two levels of access, not a scope per permission.** A token already carries
its author's permissions; a second, token-sized permission language beside the
role builder would be two answers to one question (docs/06 §2). Read-only is
the one cut worth keeping separately, because "this script only reads" is a
promise worth being able to make.

**Who may make one is a permission**, `api_token.create`, granted to org admins
and managers. Seeing and revoking your own tokens needs nothing: somebody who
lost the permission must still be able to kill what they made. Employees can be
given it through a custom role.

**The value is shown once**, prefixed `wos_` so a leaked one is recognisable in
a paste; only its SHA-256 digest is stored, like every session's. Making and
revoking one are audit events.

### What ends a token

- Its expiry, or being revoked from Settings → API tokens.
- Its author's membership ending — on the very next request, like a session.
- **Turning a second factor on or off**, and **"sign out everywhere else"** on
  the Signed in screen. Both end every other way of acting as you, tokens
  included. That is deliberate: a token minted before the credentials changed,
  or while the account may have been in somebody else's hands, is exactly what
  should stop being trusted. It will surprise somebody whose integration stops;
  it would surprise them far more to learn the attacker's token did not.

## Consequences

- One table, one lookup, one membership check: a token costs no query a
  session does not already cost.
- `SessionLifetime::clampTo` and the Signed in list now filter on `kind`.
- A person with tokens in two organizations manages each from inside that
  organization, because the tenant is the session's and never the client's.

Still owed, named so it is not assumed:

- ~~**Service accounts**~~ — paid by ADR 0059: a member that is not a person,
  with a role, reached only through tokens an administrator issues.
- **An administrator's view of everybody's tokens.** Today an admin ends
  somebody's access by ending their membership, which ends their tokens too;
  there is no list of all tokens in the organization.
- **Per-token rate limits.** Tokens share the API's existing throttles.
- ~~**The Signed in screen does not yet say**~~ Paid: it says that ending the
  other sessions ends API tokens as well.

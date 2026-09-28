# ADR 0052 — An organization can vouch for its own people

- **Status:** accepted
- **Date:** 2026-09-28
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/06` §1, `docs/10` Phase 7, ADR 0028, ADR 0030, ADR 0033, ADR 0034, ADR 0049, ADR 0050, ADR 0051

## Context

docs/10 lists "SSO/SAML" under Phase 7 Security and nothing else says a word
about it: docs/06 §1 describes a password, a second factor and a session, and
stops. Every enterprise buyer asks for it first, because it is how they keep
the list of who works for them in one place — an employee removed from the
company directory should lose every tool that trusts it, on the same
afternoon.

Three things about this product shaped the design before any SAML did:

- **The session cookie is set by the web server, not the API** (docs/06 §1).
  An identity provider posts its answer to a URL in the browser; that URL has
  to be the web server's, which then hands the answer to the API.
- **A person can belong to several organizations** (ADR 0050), and a user
  account is global. One organization's IdP must never become a way into
  another organization, or into the account itself.
- **An organization can already require things of its people** — a second
  factor (ADR 0033), a session lifetime (ADR 0028) — and each of those
  learned to reach sessions that were already open.

## Decision

**SAML 2.0, service-provider initiated, one identity provider per
organization, with the verification done by `onelogin/php-saml`.**

### Installed, not written

TOTP was written by hand (ADR 0030) because it is forty lines of arithmetic
against an RFC. XML signature verification is the opposite: the known attacks
(signature wrapping, a reference to a different element than the one read,
comments splitting a NameID) are the kind a hand-rolled verifier learns about
from an incident. `onelogin/php-saml` is what most PHP service providers run.
It lives behind `Identity\Infrastructure\Saml\SamlToolkit` and nothing else in
the product sees a DOM document.

The toolkit checks everything with nothing relaxed: a signature by the
organization's certificate on the response or the assertion (at least one, and
every one present must verify), the issuer, the audience, the destination and
recipient, the validity window, schema validity, and that the response answers
**this** request. Unsolicited (IdP-initiated) responses are refused: without a
request to answer there is nothing to stop a captured response being posted
again.

One accommodation, contained: the toolkit learns the URL a response "arrived
at" from `$_SERVER`, which in the API describes the API, not the web page the
IdP posted to. `SamlToolkit::verify()` sets the toolkit's base URL to the web
server's assertion consumer for one call and restores everything in a
`finally`. The alternative — switching the destination check off — is the
attack that check exists for.

### Three steps, because there are two browsers' worth of trust

1. **start** — an address names a domain, the domain names one organization's
   connection, and the browser is sent to its IdP. The web server also passes
   a random *binding* that it keeps in an HttpOnly cookie.
2. **consume** — the IdP's answer (posted cross-site to the web server, passed
   on to `POST /auth/sso/acs`) is verified. What comes back is a one-minute
   completion code, **not a session**.
3. **complete** — the web server redirects the browser to itself (a same-site
   navigation, so the `SameSite=Lax` binding cookie is sent) and calls
   `POST /auth/sso/complete` with the code **and** the binding. Only now is a
   session issued.

Step 2 cannot tell whose browser it is in: a cross-site POST carries no
`Lax` cookie. Issuing the session there would allow login CSRF — an attacker
starts a sign-in as themselves, captures their IdP's answer, and makes a
victim's browser post it; the victim then works inside the attacker's account.
The binding makes an answer started in one browser impossible to finish in
another. Every pending step is single-use (`Cache::pull`); a replayed answer
finds nothing to answer.

### What an IdP may vouch for

- **Only existing members.** No account is created on the fly. An IdP vouching
  for an address is not the organization deciding that person belongs in it;
  invitations (ADR 0017) remain the way in.
- **Only addresses in the connection's domains.** Domains are unique across
  the whole product (`uq_ssod_domain`): one address, one IdP. Without this, an
  organization's IdP could sign in anybody who also belonged to it, by any
  address.
- **Only into its own organization.** A session an IdP vouched for
  (`sessions.authenticated_by = 'sso'`) cannot switch organization (ADR 0050);
  the switcher lists only where it stands.
- **Not the account's own credentials.** Such a session cannot enrol a second
  factor. Otherwise a misconfigured or hostile IdP could put its own
  authenticator on the account of somebody who also works elsewhere. Turning a
  factor off and new recovery codes already ask for the password.

The same column exempts the session from the organization's second-factor
confinement (ADR 0033): the IdP is where that organization's factor lives, and
asking again would make people enrol a TOTP app for a password they never use.

### Requiring it

`sso_connections.enforced` refuses passwords into the organization, with three
edges worked out rather than left to chance:

- **It cannot be switched on until somebody has signed in through the
  connection** (`last_succeeded_at`, coupled by a CHECK). A wrong certificate
  plus enforcement is an organization nobody can enter.
- **Switching it on ends every session a password opened there**, except the
  one asking, and says how many. A requirement that left thirty days of
  password sessions running would not be one — ADR 0028's lesson.
- **The break-glass:** people holding `sso.manage` may still sign in with a
  password, so a down IdP or a bad certificate never locks out the people who
  can fix it. Every use is audited as `auth.sso_bypassed` in that organization.

A password sign-in with no organization named skips organizations that require
SSO rather than refusing outright, so somebody in one that does and one that
does not lands where a password is accepted. Switching into an organization
that requires SSO is refused unless the break-glass applies.

### Configuring it

`sso.manage`, org admin only — not `organization.manage_settings`, because
naming a third party whose word signs anybody in is a different power from
tuning an idle timeout. Every write asks for the password again (ADR 0034),
which also keeps API tokens out (ADR 0049). The certificate is parsed on save,
and the audit entry records its SHA-256 fingerprint, not the PEM.

Both new tables have `organization_id NOT NULL` and get the Row-Level Security
policy in the migration that creates them (ADR 0051). Sign-in reads them across
tenants on purpose, through `runAsPlatform()`.

## Consequences

- An organization can point sign-in at Okta, Entra ID, Google Workspace or any
  SAML 2.0 IdP, and require it. `https://mocksaml.com` works for development
  (domains `example.com` and `example.org`).
- **One IdP per organization**, enforced by a unique index. Two is a real case
  (a merger, a contractor directory) and the day it is needed the question
  "which one signs this person in" has to be answered on purpose.
- The redirect-then-complete dance adds one round trip to an SSO sign-in.

Still owed:

- **Domain verification.** A domain is claimed by typing it; nothing checks a
  DNS record. The rules above bound what a false claim can do — the claimant's
  IdP can reach only people who are already members of the claimant's
  organization, only there, and cannot touch their accounts — but a claim also
  blocks the real owner from claiming it, which is a support ticket today and
  a verification step tomorrow.
- **Just-in-time provisioning and SCIM.** An IdP that could create members
  would need a default role and a deprovisioning story; SCIM is that story.
- **Re-authentication through the IdP.** The "prove it again" prompt (ADR 0034)
  still asks for the password. A person who only ever signs in through SSO may
  not know it; a round trip with `ForceAuthn` is the fix.
- **Single logout.** Ending the IdP session does not end this product's; the
  session lifetime and idle timeout bound it meanwhile.
- **Encrypted assertions** are refused (the toolkit would need a
  service-provider private key, and a key to rotate).
- The login limiter keys on the requester's IP, and every request reaches the
  API from the web server's address. This predates SSO — it applies to the
  password form equally — and is recorded here because the new `sso` limiter
  inherits it.

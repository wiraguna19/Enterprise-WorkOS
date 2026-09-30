# ADR 0059 — An integration is a member that is not a person

- **Status:** accepted
- **Date:** 2026-09-30
- **Phase:** after Phase 7 (`docs/10`, the queue, item 6)
- **Relates to:** ADR 0049, ADR 0051, ADR 0033, `docs/06` §1–2

## Context

ADR 0049 made an API token a session acting as the person who made it, and
named what that left owed: "a token that belongs to no person. It needs an
answer to whose permissions, and who is accountable in the audit log." Until
now an integration died with its author's membership, and everything it did
was filed under their name.

## Decision

**A service account is a `users` row of kind `service` with exactly one
membership. It holds a role an administrator chose, and is reached only
through tokens an administrator issued.**

- **A membership, not an organization-owned token.** Every question the
  product asks of an actor — which permissions, which projects, which rows
  under Row-Level Security (ADR 0051), whose name in the activity log — is
  already answered for a membership. A token owned by the organization would
  need a second answer to each, and the second answer is always the weaker
  door.
- **`users.kind`**, `person` or `service`, with a CHECK that a service user has
  no password, no second factor and is not a platform administrator. Its email
  is `svc-<uuid>@service.invalid` — RFC 2606 reserves `.invalid` for exactly
  this — because `users.email` is NOT NULL and UNIQUE.
- **What it cannot be**, each refused by name:
  - an administrator (`service_account.role_too_powerful` for `org_admin`) —
    an integration needs a narrower role, and a custom role can say exactly
    which;
  - signed in to — there is no password, so the login's own check refuses it;
  - held to a second factor — `RequireSecondFactor` lets it through: there is
    no person to enrol one, and the administrator who issued its token already
    met the organization's requirement (ADR 0033);
  - given work to hold (`service_account.cannot_hold_work`) — it may create
    and change work through its token; a person answers for it;
  - offered as a colleague — `/people` lists people only, so no assignee
    picker, @mention list or directory shows it.
- **Its tokens are ordinary API tokens** (ADR 0049), made by the same
  `ApiTokens::issue()`: same prefix, digest-only storage, lifetime and access
  levels. `LimitApiTokens` refuses them — and every token — the
  `service_accounts.*` routes, beside `auth.*` and `api_tokens.*`: a token that
  could reach them could issue its own successor or promote the account it
  belongs to.
- **`service_account.manage`**, granted to org admins, governs every route.
- **Deactivating** ends the membership and every token on its next request. It
  is not a delete: the activity log names the account as the actor of what it
  did, and that record outlives it.
- **Settings → Service accounts** creates one with a role, issues and revokes
  its tokens (the value shown once), and deactivates it. The roles offered are
  the organization's minus those the API serves as refused.

## Consequences

- An integration survives whoever set it up leaving, and the activity log says
  "Warehouse integration created ENG-201", not an administrator's name.
- Role changes after creation go through the ordinary role screens, which act
  on memberships; a service account is one.
- Every place that lists memberships as PEOPLE must ask for `kind = person`.
  `/people` does; any new listing inherits the question.

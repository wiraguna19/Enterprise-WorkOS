# ADR 0048 — A rule names an endpoint, never an address

- **Status:** accepted
- **Date:** 2026-09-28
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/02` §7, `docs/10` Phase 7, ADR 0002, ADR 0014, ADR 0038

## Context

docs/02 §7 lists `webhook(url)` among the rule actions, marked Phase 7, and
docs/10 promises "webhooks + outbound integrations". ADR 0014 kept the action
out of `ActionExecutor` deliberately:

> the second lets customer-authored data reach the internet from inside the
> queue. Both are Phase 7 features in `docs/10`, and both need a bound before
> they need a form.

Grepping for `webhook` found that sentence, the `ActionExecutor` docblock, and a
word in the volume seeder's list of fake subjects. Nothing else.

Taken literally, `webhook(url)` has three problems, each of which this product
would have shipped in its own way:

1. **Anybody who may write a rule may send the organization's work anywhere.**
   `workflow.manage` would quietly include "may exfiltrate".
2. **The server makes a request to an address a customer typed, from inside
   its own network.** That is server-side request forgery with a settings
   screen: `https://169.254.169.254/` is the cloud metadata service, and a
   delivery log that printed the response would read it out.
3. **A slow or dead receiver sits inside the rule engine**, holding a
   transaction and a worker, and its failure counts against the rule.

## Decision

**A rule names a registered ENDPOINT, never a URL.** The action is
`webhook` with `with: {endpoint_id}`.

- **`webhook_endpoints`** holds the addresses, registered by whoever holds the
  new `webhook.manage` (org admin). Where data may go is decided once, by the
  person whose job it is; which rule sends what stays with the rule author,
  who sees endpoint **names** through `workflow-vocabulary` and never an
  address. That is the ADR 0038 lesson again: a form must not read an
  administration endpoint.
- **Every address passes `DestinationGuard`** when it is registered, when it is
  changed, and again before every send: https only, no credentials in the URL,
  and **every** address the name resolves to must be public — private,
  loopback, link-local (the metadata service) and CGNAT ranges are refused.
  The checked address is **pinned** (`CURLOPT_RESOLVE`), so the connection goes
  to what was checked, not to whatever DNS answers a moment later. Redirects
  are not followed; a 3xx is recorded as the answer.
- **The action does not make the request.** It inserts a `webhook_deliveries`
  row — idempotent on `(endpoint, dedupe_key)`, `ON CONFLICT DO NOTHING` — and
  queues `DeliverWebhook` on the `low` queue after the transaction commits. A
  receiver that takes five seconds, or never answers, cannot stall rule
  evaluation or roll back the run that asked for it.
- **The row owns its retries.** An attempt claims the row with a conditional
  UPDATE that also moves `next_attempt_at` a lease into the future, so a
  duplicate job finds it not due. Five attempts (1 min, 5 min, 30 min, 2 h),
  then `abandoned`. Five abandoned deliveries in a row switch the endpoint off
  with the reason written on it — the posture a failing rule already has.
  Switching it back on clears the count.
- **Signed**: `X-WorkOS-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "t.body">`,
  with `X-WorkOS-Event` and `X-WorkOS-Delivery`. The timestamp is inside the
  signed string, so a captured delivery cannot be replayed later with a fresh
  date.
- **The secret is encrypted, not digested.** Credentials this product verifies
  are stored as digests; a signing secret is one it USES, and a digest cannot
  sign. Encrypted with the application key, as the TOTP secret already is,
  shown once when created or rotated, and hidden from every serialisation.
- **What is sent** is `{subject: {type, id, reference}, facts, causation_id}`,
  where `facts` is filtered to `RuleVocabulary::FIELDS` — a list that is
  documented, served and held to reality by a test. What leaves the product is
  a published shape, not whatever a listener happened to put in an array.
- **What comes back is not kept.** A delivery records the status code and a
  sentence, never the response body: printing what a customer-chosen address
  returned is how a webhook screen becomes a way to read pages nobody meant to
  expose.
- **The audit log records the HOST, not the URL.** A chat tool's incoming
  webhook URL is itself a credential, and the audit log is read by more people,
  for longer, than the endpoint screen.
- **Deleting an endpoint a rule still sends to is refused**, with the rules
  named (`webhook.endpoint_in_use`). Otherwise each such rule would fail on
  every match until it disabled itself — the removal reported days later as a
  broken rule. Switching off is the reversible answer.

Two rules sending the same change to the same endpoint produce ONE delivery.
The payload names the change, not the rule; that is the posture `notify`
already takes with its dedupe seed.

## Consequences

- `ActionExecutor` has six handlers. `create_work_item` is still absent, for
  ADR 0014's reason: each item it made would start a new causation chain, and
  ADR 0002's recursion guard could not see the loop.
- The rule builder composes `webhook` alongside `notify` and `escalate`, and is
  not offered it when no endpoint exists — a choice whose only outcome is a
  refusal.
- Endpoints live in the Workflow module, beside the only thing that sends to
  them. When subscriptions arrive (below), that is the moment to decide whether
  they earn a module of their own.

Still owed, and named so they are not assumed:

- **Event subscriptions.** An endpoint receives what rules send it; it cannot
  yet subscribe to "every work item created" without a rule. That is the other
  half of "outbound integrations", and it will reuse the delivery machinery
  unchanged.
- ~~**No sweeper.**~~ Paid: `workflow:nudge-webhook-deliveries` runs every
  five minutes and re-dispatches pending rows more than five minutes past due
  (or never attempted). The job's lease makes a nudge that races a healthy
  retry harmless. Dev runs no scheduler; run the command by hand there.
- **Secret rotation has no overlap window.** The new secret signs immediately
  and the old one stops. Zero-downtime rotation needs a second column and a
  rule for when the old one dies.
- **IPv6 receivers are refused as unresolvable.** Safe and fixable, where
  half-checking IPv6 (mapped addresses) would be neither.
- **`webhook_deliveries` is not partitioned and has no retention.** That
  belongs to Phase 7's "data export and retention", and a partition scheme
  chosen before anything reads the table would be a guess.
- **Public API tokens** — the natural next step for a receiver that wants more
  than the facts — are their own slice.

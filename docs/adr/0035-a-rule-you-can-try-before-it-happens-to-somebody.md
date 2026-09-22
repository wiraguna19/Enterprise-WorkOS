# ADR 0035 — A rule you can try, before it happens to somebody

- **Status:** accepted
- **Date:** 2026-09-22
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/02` §7, ADR 0014, ADR 0019, ADR 0034

## Context

`workflow.run_rule` has been in the permission catalogue since Phase 3, ticked
in the role builder, and consulted by nothing. It was the last entry on the bill
in `EveryPermissionMeansSomethingTest`, and its note read: *"the rule screens
show what a rule DID and cannot make it run."*

The cost is not the unread key. It is what an administrator does instead. The
first question anyone asks of any automation is **why didn't my rule fire?** The
run log answers that for rules that were triggered; it cannot answer it for the
rule somebody wrote five minutes ago, because nothing has happened to it yet. So
they go and break a real work item to find out — change a priority, move a
status, unassign somebody — on data other people are working in.

## Decision

**Preview first, and the run button does not exist until a preview has been
seen.** One endpoint, `POST /workflow-rules/{id}/run`, defaults to a preview and
applies only when the body says `apply: true`. A route whose default behaviour
changes live data is a route somebody triggers by exploring.

Sequencing rather than a confirmation dialogue: the preview IS the
confirmation, and it says what a dialogue cannot — which actions are about to
happen, to which item, with which arguments.

**A preview is not written to the run log.** That log records what the system
did, and a preview did nothing. The audit log records the real run, because who
asked for it is a different question from what the engine did (ADR 0019).

**A real run is the engine's own path.** `RuleEngine::runNow()` goes through the
same conditions, actions, failure counting and run logging as an event-driven
evaluation, with a causation id of its own so a cascade started by hand can be
followed like any other. A "try it" that took a different path would be testing
the button rather than the rule.

**A hand-run does not clear the rule's failure count.** The engine clears it on
success, because the disable threshold counts CONSECUTIVE failures — but health
describes how a rule behaves on the events it was written for, and a success
against an item somebody chose says nothing about the ones it keeps failing on.
Letting a hand-run clear it would make the red badge something an administrator
removes by pressing a button rather than by fixing anything. Found by watching a
badge go from "1 recent failures" to "running" immediately after a try-it run —
the feature quietly undoing the product's own alarm.

**The run log now records WHO.** `triggered_by_membership_id` is null for every
run the system did on its own — which is every run before this — and names the
person for a hand-run. Without it, the screen that answers "why did this work
item move?" says "a rule did it" and hides the button press a second earlier.

**Three verdicts, not two.** Matched, did not match, and **cannot be judged by
hand** — the last for a rule whose condition asks about the moment. The first
version showed the confident "conditions do not match" over a paragraph
explaining that the condition could not be answered, which is two sentences that
cannot both be true; the confident one is the one people read. A screenshot made
it obvious in a second.

**A manual run sees the item, not the moment.** Conditions may name facts that
exist only because an event just happened: `to_state_key`, `from_category`,
`comment`. An item sitting still has no such moment. So the preview NAMES those
fields rather than reporting a confident "did not match" — a verdict that would
send somebody rewriting a rule that was never wrong. This is the one place the
preview deliberately refuses to be simpler than the truth.

**The fact builder is now shared.** It lived privately inside
`DispatchRuleEvaluation`; the manual run needs the same world the engine sees,
and two fact builders that must agree would disagree within a phase — the rule
that fired for the event would skip in the preview, and the preview would be the
thing people stopped trusting.

**`workflow.run_rule`, not `workflow.manage`.** Writing a rule and making one
happen to a real work item right now are different acts, and the catalogue has
said so since Phase 3. This slice does not get to merge them because it is
easier.

## Consequences

- **The permission bill is empty.** Ten entries were written out on 2026-09-16,
  each with what it was owed; this is the last of them. What that inventory was
  worth is worth stating: every one of the ten was a promise the role builder
  displayed and the server did not keep, and none of them would have been found
  by reading the code that was there.
- The reference is taken as typed — trimmed and upper-cased — because this form
  is filled in by somebody with "ENG-142" in front of them, and the index on
  `upper(reference)` exists for exactly that.
- A reference in another organization answers 404, like every other cross-tenant
  read: "that item is real but not yours" is a fact nobody outside is owed. **The
  first version of the lookup leaked it.** `DB::table('work_items')` carries no
  organization scope, so a Globex reference resolved happily inside Acme and this
  endpoint became a way to ask whether somebody else's work item exists. The test
  written for the rule caught it; the code did not look wrong. Reaching past the
  model to save an import is how a leak gets written by somebody who knows
  better, and `TenantIsolationTest` cannot see it because it watches models, not
  the query builder.
- **Not built:** running a rule against a set of items, and scheduling one. Both
  are plausible and neither is what the missing permission was for — the gap was
  a person unable to find out what their own rule does without experimenting on
  colleagues.
- `docs/10`'s Phase 7 list now has one item left in the whole phase: SSO/SAML.

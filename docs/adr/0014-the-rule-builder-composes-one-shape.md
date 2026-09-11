# ADR 0014 — The rule builder composes one shape, and refuses the rest out loud

- **Status:** accepted
- **Date:** 2026-09-11
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/02-domain-model.md` §7 (the rule engine),
  `docs/12-risks-tradeoffs.md` §10 (customer-authored data),
  ADR 0002 (the recursion guard)

## Context

`ConditionEvaluator` reads a predicate tree — `all`, `any`, `not`, and leaf
comparisons over thirteen operators — and `ActionExecutor` runs five action
types with free-form configuration. That grammar is right for the engine: it is
small, total, and stored as data.

It is not a form. A builder that exposed all of it would be a JSON editor with
dropdowns, and the shape of that mistake is already recorded here: the
recurrence picker deliberately does not take RFC 5545, because **a field that
takes the whole grammar is a field nobody can fill in without reading a spec.**

The engine's totality makes the stakes asymmetric. A malformed predicate is
`false`, never an exception — correct for a queued job, and the reason a
misspelt field produces a rule that **never fires and never errors.** Nothing
in the product would ever report it. The same asymmetry runs the other way for
actions: an unknown action type THROWS, inside a worker, hours later.

## Decision

**Three separate refusals, each at the layer that can afford it.**

1. **The API validates a rule at the door**, against the vocabulary that will
   run it: the trigger must be one `WorkflowRuleModel` lists, every operator one
   `ConditionEvaluator` implements, every action one `ActionExecutor` registers,
   and every field one `RuleVocabulary::FIELDS` declares. A refusal names what
   it refused — `` `priorty` is not a fact this system supplies to a rule `` —
   and reports every offender in the predicate at once rather than the first.
2. **The vocabulary is served, not copied.** `GET /workflow-vocabulary` derives
   its triggers, operators and actions from the classes that implement them.
   The interface may not keep its own list: two lists that must agree
   eventually will not, and this codebase has four scars from exactly that.
3. **The builder composes one shape** — a flat `{"all": [leaf, …]}` and the
   `notify` and `escalate` actions — and **refuses to open a rule outside it**,
   showing the stored JSON and saying why. A form that flattened a predicate it
   could not draw would delete the half it did not understand on the next save.

`create_work_item` and `webhook` stay out of `ActionExecutor`, and therefore out
of the vocabulary and the builder. The first can generate unbounded work through
a loop ADR 0002's recursion guard cannot see, because each item is a new
causation chain; the second lets customer-authored data reach the internet from
inside the queue. Both are Phase 7 features in `docs/10`, and both need a
bound before they need a form.

## Consequences

- **A rule the builder cannot express is still administrable**: it renders in
  full, it can be switched off, and its run log is unchanged. Only editing is
  withheld, and the screen says so in a sentence rather than by disabling a
  control.
- `RuleVocabulary::FIELDS` is a genuine second copy — the facts are assembled
  per trigger by listeners, and nothing can derive them. `RuleVocabularyTest`
  holds the declaration to what a real event produces, so a fact that stops
  being supplied fails in CI instead of silently never matching.
- **Editing or re-activating a rule clears its failure count.** The engine
  disables a rule after five consecutive failures; leaving the count where it
  was would leave a rule somebody has just fixed one failure away from being
  switched off again, which reads as the fix not working.
- A `PATCH` carries only the fields that changed. A form posting the whole rule
  would turn "switch this off" into a rewrite with whatever the client last
  read, silently reverting an edit made a minute earlier.
- The builder's operator phrasing lives in the interface and falls back to the
  operator key. Phrasing is a UI concern; **what EXISTS is the server's**, and
  an operator the phrase list does not know still appears, spelled as itself.

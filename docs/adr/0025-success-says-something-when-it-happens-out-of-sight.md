# ADR 0025 — Success says something, when it happened out of sight

- **Status:** accepted
- **Date:** 2026-09-17
- **Phase:** 7 (Enterprise Foundation)
- **Relates to:** `docs/07` §7, `docs/09` §5, ADR 0024

## Context

Failure has always spoken in this product: every server action returns
`{ error }`, and every screen prints it next to the control that failed.
Success has always been silent. The whole answer to "did that work?" was "the
list looks different now".

That is a real answer when the list is on screen. It is no answer at all when
the effect is somewhere else — and most of Phase 7's acts are exactly that
shape. Granting a role changes what somebody else can do on their next request.
Ending a session signs out a device in another room. Revoking an invitation
kills a link in somebody's email. Switching a rule off stops the product doing
something for everybody, everywhere. In each case the screen shows a row
disappearing, and the thing that actually happened is invisible.

## Decision

**A toast for effects that happen out of sight, or acts consequential enough to
deserve saying out loud. Nothing else.**

The rule has to be this narrow, because the failure mode of confirmation is not
missing it — it is having so much of it that people stop looking at the corner
where it appears. A toast on every notification-preference toggle would teach
somebody to ignore the toast that says a person was erased.

So:

- **The effect is visible where you are standing** → the screen IS the
  confirmation. A renamed department shows its new name; a saved preference
  shows its new state. No toast.
- **The effect is elsewhere, or irreversible** → say it. Grant, revoke, deny,
  lift, erase, end a session, revoke an invitation, stop a recurrence, switch a
  rule off.
- **Errors never become toasts.** They stay inline, beside the control that
  failed, because that is where the person is looking and because an error that
  scrolls away on a timer is an error nobody can act on. The one exception this
  ADR does NOT make is "errors too, for consistency" — consistency is not worth
  moving a message away from the thing it is about.

**The message says what changed, not that a request succeeded.** "Granted. It
applies on their next request." rather than "Saved". A confirmation that only
says "Saved" makes somebody go and check, which is what the confirmation was
supposed to save them.

**`aria-live="polite"`, never assertive.** These announce something that has
already gone right; interrupting a screen reader mid-sentence to say "Saved" is
the assistive-technology version of a popup. Errors keep their own `role="alert"`
inline, where interruption is warranted.

**Five seconds, with a close control.** The timer is a guess about somebody
else's reading speed, so the guess is not the only way out.

## Consequences

- `useToast()` returns a no-op outside the provider rather than throwing. A
  component rendered in a harness without the shell should not crash over a
  confirmation message.
- The provider is client state in a product with no client data layer
  (ADR 0012). That stands: this is ephemeral UI state about what just happened,
  not server data being cached — nothing reads it back, and a refresh is
  supposed to lose it.
- **Server actions still return `{ error }` only.** The message is written at
  the call site, in the component that knows what the person just did, rather
  than passed back from the action — the same sentence would otherwise have to
  be invented by a layer that does not know whether it is in a table row, a
  form or a bulk control.
- Nothing about this makes the product tell somebody their action worked when it
  did not: every toast fires on `result.error === null`, which is the same
  condition that already decided whether to clear a form.

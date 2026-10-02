# ADR 0060 — A second interface language

- **Status:** accepted
- **Date:** 2026-10-02
- **Phase:** after Phase 7 (`docs/10`, the queue, item 7)
- **Relates to:** `docs/07` §1, `docs/03` (`users.locale`), ADR 0033

## Context

`users.locale` has existed since the first migration, defaulting to `en`, and
`/auth/me` has served it ever since. Nothing read it and nothing could change
it. The queue named Indonesian as the last item and left one question open:
the web interface only, or API refusals, notifications and email as well.

## Decision

**The web interface first, in Indonesian and English. The API, notifications
and email stay English for now. The structure must let them follow without a
rewrite.**

- **The person's choice lives in `users.locale`** (`en` or `id`). It is set by
  `PATCH /auth/me` and chosen under Settings → Language, so it follows the
  person to every device. The route is named `auth.me.update`, which means an
  API token is refused it by the existing `auth.*` rule (ADR 0049). A
  script has no interface to translate.
- **The web reads it from `/auth/me`,** which every authenticated page already
  awaits, so a choice made on another device applies on the next render. A
  `wos_locale` cookie holds a copy for the screens that run before anybody is
  signed in (sign-in, invitations) and for `<html lang>`. It is written at
  sign-in and whenever the choice changes.
- **Typed dictionaries in `src/i18n`, not next-intl.** The roadmap named
  next-intl. It was reconsidered for two reasons:
  1. The rule this item carries, "translate a screen completely or not at
     all", is best kept by the compiler. `messages/id.ts` is typed as
     `Messages`, the shape of `messages/en.ts`. A key missing from
     Indonesian, or a key Indonesian has and English does not, fails
     `tsc`. next-intl would report it at runtime, on the screen.
  2. Installing a package through the Cowork folder mount has corrupted
     files in this repository before. A hundred lines of our own code need
     no install.

  What it costs: no ICU message syntax. Interpolation is `{name}`. Plurals
  are a key pair, `x.one` / `x.other`, chosen by `Intl.PluralRules`
  (Indonesian has no grammatical plural, so both of its forms read the
  same). If messages ever need gender or nested selects, that is the moment
  to adopt a library, and the dictionaries carry over unchanged.
- **Dates and numbers take the locale explicitly.** `lib/format.ts` formats
  with `en-GB` or `id-ID` according to an argument. It is never ambient, for
  the same reason the time zone is not (`docs/07` §1).
- **A screen is translated completely, or it is left English.** Screens move
  over one at a time. The list of translated screens is kept in `docs/10`.
- **Shared components take the language as a prop, defaulting to English.**
  A row, a due date or a workload bar appears on a dozen screens and renders
  on the server on some of them and on the client on others, so it cannot use
  a hook. It takes an optional `locale` and calls `translator(locale)`. A
  translated screen passes its locale. A screen not yet translated passes
  nothing and stays wholly English, which is the rule above kept by a default
  value. Client components that exist only on translated screens use
  `useT()`.
- **Seeded users stay `en`.** The end-to-end specs find controls by their
  English names.

## Consequences

- An Indonesian reader sees English on every screen not yet moved over.
  That is the trade the rule above prefers over half-translated screens.
- A new string on a translated screen is a key in both dictionaries, or the
  build fails. That is the point.
- API refusals arrive in English inside an Indonesian page until the API
  learns `Accept-Language`. That is the next slice of this item if it is
  wanted, and the cookie and `users.locale` are already where it would read
  from.

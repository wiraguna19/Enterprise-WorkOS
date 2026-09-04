# Web

The Next.js app. `docs/07-frontend-architecture.md` is the design; this file is
only what you need to run it.

## Development

```bash
npm run dev
```

`apps/web/.env.local` must point at the API by IP, not by name:

```
API_URL=http://127.0.0.1:8000/api/v1
```

Node resolves `localhost` to `::1` and `php artisan serve` binds `127.0.0.1`
only, so `localhost` here fails in a way that reads like the API being down.

## Gates

```bash
npm run typecheck     # tsc --noEmit
npm run lint          # eslint
```

## End-to-end tests

The flows in `docs/11-testing-strategy.md` §4, at both a desktop and a 375px
viewport. Install once:

```bash
npm i -D @playwright/test
npx playwright install chromium
```

Then, with **three processes running** — and the third is the one people forget:

```bash
php artisan serve                    # api
php artisan queue:work --tries=1     # rules, notifications, exports
npm run test:e2e:mobile              # web is started for you
```

Without the queue worker the rule engine is dormant: submitting for review never
creates an approval, and the flow fails at a step that looks like a UI bug. The
suite says so rather than timing out silently.

The end-to-end suite is deliberately outside the app's `tsconfig.json`: it runs
in Node under Playwright, it is the only thing here that needs
`@playwright/test`, and `npm run typecheck` should stay green on a checkout that
has not installed browsers.

Written so far, of the fifteen in `docs/11` §4:

| | flow | file |
|---|---|---|
| 14 | a Globex user cannot reach an Acme URL | `e2e/cross-tenant.spec.ts` |
| 15 | a manager approves a submission at 375px | `e2e/mobile-approval.spec.ts` |

Each flow arranges its starting position through the API, performs the steps
under test **through the interface**, and asserts the resulting data through the
API again. A test that drives the API and then checks the API proves the API
twice and the product not at all — which is not academic here: the button flow
15 taps did nothing at all until `6d2d146`, while every API test passed.

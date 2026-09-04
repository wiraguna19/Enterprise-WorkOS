import { defineConfig, devices } from "@playwright/test";

/**
 * End-to-end flows (docs/11 §4).
 *
 * That section lists fifteen flows and says the suite runs "on desktop and at a
 * 375px viewport". None of it existed: there was no harness, no config, no
 * spec — the fifteenth flow is also the Phase 5 exit criterion, which is why
 * this starts with that one rather than with the login happy path.
 *
 * Three processes must be running, and the third is the one people forget:
 *
 *     php artisan serve                  (api)
 *     npm run dev                        (web — started for you, see below)
 *     php artisan queue:work --tries=1   (rules, notifications)
 *
 * Without the worker the rule engine is dormant: submitting for review never
 * creates the approval, and the flow fails at a step that looks like a UI bug.
 * The spec checks for it explicitly and says so.
 *
 * Install once:  npm i -D @playwright/test && npx playwright install chromium
 */
export default defineConfig({
  testDir: "./e2e",
  // One worker: these flows move shared seeded data through a workflow, and two
  // of them racing would produce failures that are about the test suite rather
  // than about the product.
  workers: 1,
  fullyParallel: false,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI ? "github" : "list",

  use: {
    baseURL: process.env.E2E_WEB_URL ?? "http://localhost:3000",
    // On failure, the two artefacts worth having: what the screen looked like,
    // and what the browser did to get there.
    screenshot: "only-on-failure",
    trace: "retain-on-failure",
  },

  projects: [
    {
      name: "mobile",
      // The viewport docs/11 §4 names. A phone-sized run is not a smaller
      // desktop: the sticky action bar, the collapsed navigation and the
      // one-column layout are only exercised here.
      use: { ...devices["iPhone SE"] },
    },
    {
      name: "desktop",
      use: { ...devices["Desktop Chrome"], viewport: { width: 1280, height: 800 } },
    },
  ],

  webServer: {
    command: "npm run dev",
    url: process.env.E2E_WEB_URL ?? "http://localhost:3000",
    reuseExistingServer: true,
    timeout: 120_000,
  },
});

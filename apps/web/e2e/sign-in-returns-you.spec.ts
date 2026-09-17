import { expect } from "@playwright/test";
import { test } from "./support/auth";

/**
 * Signing in takes you back where you were (ADR 0032).
 *
 * `proxy.ts` has written `?next=<path>` on every bounce to the sign-in screen
 * since it was written, and nothing read it: somebody whose session ended while
 * reading a work item signed in and landed on the home page, with the URL they
 * wanted sitting in the address bar of the page that had just sent them away.
 *
 * Its own spec, and a fresh context every time, because the rest of the suite
 * deliberately reuses a saved session (rate limits, `support/auth.ts`) and so
 * can never see this at all. Tono is used for the same reason: nobody else
 * signs in as him, so this spec does not spend anybody else's five attempts.
 */
const TONO = "tono@acme.test";

test.describe("signing in returns you to where you were", () => {
  test("a bounced request comes back after the password", async ({ browser, viewport }) => {
    const context = await browser.newContext({ viewport: viewport ?? undefined });
    const page = await context.newPage();

    // Asked for while signed out. The proxy bounces it and remembers.
    await page.goto("/settings/sessions");

    await expect(page).toHaveURL(/\/login\?next=%2Fsettings%2Fsessions/);

    await page.getByLabel("Email").fill(TONO);
    await page.getByLabel("Password").fill("password");
    await page.getByRole("button", { name: /sign in/i }).click();

    await expect(
      page,
      'Signed in and landed somewhere else. The `next` the proxy wrote is the '
        + 'whole point of the parameter; ending up on "/" is the defect ADR 0032 '
        + 'describes.',
    ).toHaveURL(/\/settings\/sessions$/);

    await context.close();
  });

  test("it refuses a destination that leaves this app", async ({ browser, viewport }) => {
    const context = await browser.newContext({ viewport: viewport ?? undefined });
    const page = await context.newPage();

    // The attack the validation exists for: a destination taken from a URL and
    // followed AFTER authentication is an open redirect, and the page it lands
    // on can look exactly like the one somebody just left.
    await page.goto("/login?next=https://example.com/");

    await page.getByLabel("Email").fill(TONO);
    await page.getByLabel("Password").fill("password");
    await page.getByRole("button", { name: /sign in/i }).click();

    await expect(page).toHaveURL(/localhost|127\.0\.0\.1/);
    await expect(page).not.toHaveURL(/example\.com/);

    await context.close();
  });
});

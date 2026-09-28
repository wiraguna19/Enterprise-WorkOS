import { expect, type Page } from "@playwright/test";
import { call, type Session } from "./support/api";
import { test, signedInPhone } from "./support/auth";
import { currentCounter, totp } from "./support/totp";

/**
 * docs/11 §4, flow 1 — "Login, including MFA".
 *
 * The one flow the rest of the suite deliberately never walks: every other spec
 * reuses a saved session (`support/auth.ts`), because sign-in is rate limited
 * and signing in is not what they test. So a regression in the sign-in form,
 * the code prompt, or turning a factor on and off would pass the whole suite.
 *
 * Lisa is used because nobody else signs in as her — this spec spends her five
 * attempts per quarter hour, not anybody else's. It spends three: a wrong
 * password, the password, and the password with a code. **Run it twice inside
 * fifteen minutes and the second run is refused at the form — that is docs/06
 * §1, not a failure.** Desktop only, for the same arithmetic: the phone project
 * would spend three more.
 *
 * It must leave Lisa as it found her. A factor left on would break any future
 * spec that signs her in, so the last step turns it off through the product —
 * and if an assertion fails before that, an ADMINISTRATOR takes it off her
 * (`DELETE /people/{membership}/mfa`, the unlock a locked-out person gets).
 *
 * Not Lisa herself. The first version cleaned up by signing in as her with a
 * code, which spends one more of the same five attempts — so the one run where
 * the clean-up mattered most, a second run inside the quarter hour, was the
 * run where it was refused, and it left her with a factor nobody has the app
 * for. Rina's saved session spends none of Lisa's attempts. The same unlock
 * runs FIRST as well, so a factor stranded by an earlier run is cleared rather
 * than failing this one at the code prompt.
 */
const LISA = "lisa@acme.test";
const PASSWORD = "password";
const RINA = "rina@acme.test";

test.describe("signing in", () => {
  test("a wrong password is refused, a right one gets in, and a second factor is asked for once on", async ({
    page,
    browser,
    viewport,
  }, testInfo) => {
    test.skip(testInfo.project.name !== "desktop", "Spends Lisa's sign-in attempts; once per run is enough.");

    const admin = (await signedInPhone(browser, RINA, viewport)).session;

    await unlockLisa(admin);

    // ── A wrong password: refused, in the same words as an unknown account ──
    await page.goto("/login");
    await page.getByLabel("Email").fill(LISA);
    await page.getByLabel("Password").fill("not-her-password");
    await page.getByRole("button", { name: /sign in/i }).click();

    await expect(page.getByText("These credentials do not match our records.")).toBeVisible();
    await expect(page).toHaveURL(/\/login/);

    // ── The right one ────────────────────────────────────────────────────────
    await signInWithPassword(page);

    await expect(page).not.toHaveURL(/\/login/);

    // ── Turn a second factor on, as she would: from the key, not the QR ──────
    await page.goto("/settings/two-factor");
    await page.getByRole("button", { name: "Set up two-factor" }).click();

    const secret = (
      await page.getByText("Or type the key").locator("xpath=following-sibling::p").innerText()
    ).trim();

    expect(secret, "The enrolment key was not on screen.").toMatch(/^[A-Z2-7]{16,}$/);

    let lastCounter = currentCounter();
    let factorOn = false;

    try {
      await page.getByLabel("Code from the app").fill(totp(secret, lastCounter));
      await page.getByRole("button", { name: "Turn on" }).click();

      // The recovery codes, shown once. Their presence is the proof the
      // factor is on; dismissing them is what somebody does next.
      await expect(page.getByRole("button", { name: "I have saved them" })).toBeVisible();
      factorOn = true;
      await page.getByRole("button", { name: "I have saved them" }).click();

      // ── Sign out, and back in: now the password is not enough ─────────────
      await page.getByRole("button", { name: "Account" }).click();
      await page.getByRole("menuitem", { name: "Sign out" }).click();
      await expect(page).toHaveURL(/\/login/);

      await signInWithPassword(page);

      await expect(page.getByRole("heading", { name: "Enter your code" })).toBeVisible();
      await expect(page, "The password alone must not be a session any more.").toHaveURL(/\/login/);

      // The next period's code: enrolment spent this one, and the server
      // refuses a period twice (ADR 0030).
      lastCounter += 1;
      await page.getByLabel("Code").fill(totp(secret, lastCounter));
      await page.getByRole("button", { name: "Verify" }).click();

      await expect(page).not.toHaveURL(/\/login/);

      // ── And off again, with the password, through the product ─────────────
      await page.goto("/settings/two-factor");
      await page.getByLabel("Password").fill(PASSWORD);
      await page.getByRole("button", { name: "Turn off" }).click();

      await expect(page.getByText("off", { exact: true })).toBeVisible();
      factorOn = false;
    } finally {
      if (factorOn) {
        await unlockLisa(admin);
      }
    }
  });
});

async function signInWithPassword(page: Page): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(LISA);
  await page.getByLabel("Password").fill(PASSWORD);
  await page.getByRole("button", { name: /sign in/i }).click();
}

/**
 * Take Lisa's second factor off as an administrator, if she has one.
 *
 * Asks first: `mfa_enabled` is sent to whoever may revoke it, and the unlock
 * needs a fresh password confirmation (ADR 0034), which is throttled like
 * sign-in — so it is spent only when there is something to undo.
 */
async function unlockLisa(admin: Session): Promise<void> {
  const people = await call<Array<{ id: string; email: string | null }>>(admin, "/people?limit=100");
  const row = people.find((person) => person.email === LISA);

  if (!row) throw new Error(`${LISA} is not among the people Rina can see.`);

  // The DETAIL, not the row: `mfa_enabled` is a profile field, absent from the
  // list. The first version read it off the row, found `undefined`, concluded
  // there was nothing to undo — and left the factor on for the next run to
  // meet at the code prompt.
  const lisa = await call<{ id: string; mfa_enabled?: boolean | null }>(admin, `/people/${row.id}`);

  if (lisa.mfa_enabled === null || lisa.mfa_enabled === undefined) {
    throw new Error("Rina was not told whether Lisa has a second factor — she may have lost person.deactivate.");
  }

  if (!lisa.mfa_enabled) return;

  await call(admin, "/auth/reauthenticate", { method: "POST", body: { password: PASSWORD } });
  await call(admin, `/people/${lisa.id}/mfa`, { method: "DELETE" });
}

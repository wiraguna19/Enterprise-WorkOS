import { expect } from "@playwright/test";
import { call } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * Recurring work, set up and stopped through the interface.
 *
 * Not one of the fifteen flows — docs/11 §4 never got one, which is part of how
 * this slice stayed invisible. Phase 5 shipped RRULE recurrence whole: the
 * rule, the materializer, the scheduled command, and the `recurrence_id` every
 * item it creates carries. Nothing could create one, so recurring work existed
 * only in the seed and through curl.
 *
 * The assertion that matters is the RRULE. A picker composes it from four
 * fields, and a picker that composes the wrong string is worse than a text box:
 * it is confidently wrong, and the person who set it up finds out when the work
 * does not appear on a Tuesday.
 */
const AHMAD = "ahmad@acme.test";

type Recurrence = {
  id: string;
  rrule: string;
  is_active: boolean;
  template: { title?: string; priority?: string; due_in_days?: number };
  created_count: number;
};

test.describe("recurring work", () => {
  test("a weekly rule is set up, reads back, and can be stopped", async ({
    browser,
    viewport,
  }) => {
    const screen = await signedInPhone(browser, AHMAD, viewport);
    const page = screen.page;
    const ahmad = screen.session;

    const title = `E2E recurring ${Date.now()}`;

    await page.goto("/recurring");
    await page.getByRole("link", { name: "New recurring work" }).click();

    // ── When ───────────────────────────────────────────────────────────────
    await page.getByLabel("Repeats").selectOption("weekly");
    await page.getByLabel("Every", { exact: true }).fill("2");
    await page.getByLabel("On", { exact: true }).selectOption("TH");

    // The rule is shown before it is saved, in words AND as it will be stored.
    // Asserting the sentence alone would pass on a form that displays a
    // friendly description of a string it never sends.
    await expect(page.getByText("FREQ=WEEKLY;INTERVAL=2;BYDAY=TH")).toBeVisible();
    await expect(page.getByText("Every 2 weeks on Thursday")).toBeVisible();

    // ── What appears ───────────────────────────────────────────────────────
    await page.getByLabel("Title").fill(title);
    await page.getByLabel("Priority").selectOption("high");
    await page.getByLabel("Due after").fill("3");

    await page.getByRole("button", { name: "Set up recurring work" }).click();

    await expect(page).toHaveURL(/\/recurring$/);

    // ── What the server actually stored ────────────────────────────────────
    const recurrences = await call<Recurrence[]>(ahmad, "/recurrences");
    const created = recurrences.find((row) => row.template.title === title);

    expect(
      created,
      "The form navigated without an error and the rule is not in the API's list.",
    ).toBeDefined();

    expect(
      created?.rrule,
      "The picker composed a different rule from the one it displayed, which is "
        + "the failure a picker has and a text box does not.",
    ).toBe("FREQ=WEEKLY;INTERVAL=2;BYDAY=TH");

    // Relative, never absolute: "due three days after it appears" is what a
    // recurring task means, and it is why the field is `due_in_days`.
    expect(created?.template.due_in_days).toBe(3);
    expect(created?.template.priority).toBe("high");

    // Nothing has run yet, and the row says so rather than leaving it blank.
    expect(created?.created_count).toBe(0);

    // ── Stopped, and confirmed ─────────────────────────────────────────────
    const row = page.locator("li").filter({ hasText: title });

    await expect(row.getByText("Every 2 weeks on Thursday")).toBeVisible();

    await row.getByRole("button", { name: "Stop", exact: true }).click();
    await row.getByRole("button", { name: "Stop it" }).click();

    await expect(row.getByText("stopped")).toBeVisible();

    // Deactivated, not deleted. The work a rule already created points back at
    // it, so the row has to survive being stopped — a DELETE that erased it
    // would leave those items pointing at nothing.
    const after = await call<Recurrence[]>(ahmad, "/recurrences");
    const stopped = after.find((row) => row.id === created?.id);

    expect(
      stopped,
      "Stopping the rule removed it from the API entirely, so any work it had "
        + "already created now points at nothing.",
    ).toBeDefined();

    expect(stopped?.is_active).toBe(false);
  });
});

import { expect } from "@playwright/test";
import { call } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * A busy day on the calendar can be read in full.
 *
 * A month cell shows three events and then "+N more". Until this, the count
 * was plain text: the rest of the day was on the calendar and unreachable from
 * it. The flow asserts the thing that was missing — that every item due that
 * day, the hidden ones included, can be seen and opened from the month.
 *
 * The day is the 15th of the month after next, at noon UTC: far enough out that
 * no other flow's dates land there, and noon so that it is the same calendar
 * day in every time zone the seed uses. Earlier runs leave their items on the
 * same day — which only makes it busier; the assertion is that THIS run's five
 * are all in the list, never that the list has five.
 */
const AHMAD = "ahmad@acme.test";

type WorkItem = { reference: string; title: string };

test.describe("calendar", () => {
  test("a day with more than fits opens in full", async ({ browser, viewport }, testInfo) => {
    const { page, session } = await signedInPhone(browser, AHMAD, viewport);

    const day = new Date();
    day.setUTCDate(1);
    day.setUTCMonth(day.getUTCMonth() + 2);
    day.setUTCDate(15);
    day.setUTCHours(12, 0, 0, 0);

    const month = day.toISOString().slice(0, 7);
    const project = await call<{ id: string }>(session, "/projects/ENG");
    const stamp = Date.now().toString(36);

    const items: WorkItem[] = [];

    for (let n = 1; n <= 5; n++) {
      items.push(
        await call<WorkItem>(session, "/work-items", {
          method: "POST",
          body: {
            title: `E2E busy day ${stamp} #${n}`,
            type: "task",
            project_id: project.id,
            due_at: day.toISOString(),
          },
        }),
      );
    }

    await page.goto(`/calendar?month=${month}&sources=work`);

    if (testInfo.project.name === "mobile") {
      // A phone gets the agenda, not the grid, and the agenda has no limit
      // per day — so the same question has a plainer answer there.
      for (const item of items) {
        await expect(page.getByRole("link", { name: new RegExp(item.reference) })).toBeVisible();
      }

      return;
    }

    const dayLabel = new Intl.DateTimeFormat("en-GB", {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric",
      timeZone: "UTC",
    }).format(day);

    // By the day, not by the count: "+2 more" is on every busy cell, and the
    // day is what makes this one the right one.
    await page.getByRole("button", { name: new RegExp(`more on ${dayLabel}$`) }).click();

    const dialog = page.getByRole("dialog", { name: dayLabel });

    await expect(dialog).toBeVisible();

    for (const item of items) {
      await expect(
        dialog.getByRole("link", { name: new RegExp(item.reference) }),
        `${item.reference} is due ${dayLabel} and is not in that day's list.`,
      ).toBeVisible();
    }

    // Esc closes it, and focus goes back to where it came from.
    await page.keyboard.press("Escape");
    await expect(dialog).toBeHidden();
    await expect(page.getByRole("button", { name: new RegExp(`more on ${dayLabel}$`) })).toBeFocused();

    // And an item opens from the list, not only from the cell.
    await page.getByRole("button", { name: new RegExp(`more on ${dayLabel}$`) }).click();
    await dialog.getByRole("link", { name: new RegExp(items[4].reference) }).click();

    await expect(page).toHaveURL(new RegExp(`/work/${items[4].reference}$`));
  });
});

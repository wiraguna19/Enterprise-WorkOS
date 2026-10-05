import { expect } from "@playwright/test";
import { test, signedInPhone } from "./support/auth";

/**
 * A person's delivery without a KPI (ADR 0062, "Delivery without a KPI"):
 * their manager sees how much they finished and opens the items behind it; a
 * colleague sees none of it.
 */
const AHMAD = "ahmad@acme.test";
const LISA = "lisa@acme.test";
const DAVID_MEMBERSHIP = "01900000-0000-7000-8000-000000000204";

test.describe("delivery", () => {
  test("is shown to the manager with the items behind it, and to nobody outside the line", async ({
    browser,
    viewport,
  }) => {
    const manager = await signedInPhone(browser, AHMAD, viewport);

    await manager.page.goto(`/people/${DAVID_MEMBERSHIP}`);

    const panel = manager.page.getByRole("region", { name: "Delivery" });
    await expect(panel).toBeVisible();

    const figure = panel.getByRole("link", { name: /items finished/ });
    const finished = Number((await figure.innerText()).match(/\d+/)?.[0] ?? "NaN");

    await figure.click();
    await expect(manager.page).toHaveURL(new RegExp(`/people/${DAVID_MEMBERSHIP}/delivery\\?from=`));
    await expect(manager.page.getByRole("heading", { name: "Items finished", level: 1 })).toBeVisible();

    // The figure is the length of the list behind it.
    if (finished === 0) {
      await expect(manager.page.getByText("Nothing finished in this window")).toBeVisible();
    } else {
      await expect(manager.page.getByRole("region", { name: `${finished} ${finished === 1 ? "item" : "items"} finished` })).toBeVisible();
      await expect(manager.page.getByRole("row")).toHaveCount(finished + 1);
    }

    // ── A colleague outside David's reporting line ──────────────────────
    const colleague = await signedInPhone(browser, LISA, viewport);

    await colleague.page.goto(`/people/${DAVID_MEMBERSHIP}`);
    await expect(colleague.page.getByRole("heading", { name: "David Park", level: 1 })).toBeVisible();
    await expect(colleague.page.getByRole("region", { name: "Delivery" })).toHaveCount(0);

    await colleague.page.goto(`/people/${DAVID_MEMBERSHIP}/delivery`);
    await expect(colleague.page.getByRole("heading", { name: "Items finished", level: 1 })).toHaveCount(0);
  });
});

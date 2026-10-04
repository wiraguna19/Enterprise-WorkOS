import { expect } from "@playwright/test";
import { test, signedInPhone } from "./support/auth";

/**
 * KPIs (ADR 0062): a manager defines one for a team and enters this month's
 * value; an employee sees where it stands and cannot change it.
 *
 * The name is stamped so a run never finds an earlier run's KPI, and the KPI
 * is archived through the screen at the end.
 */
const AHMAD = "ahmad@acme.test";
const SARAH = "sarah@acme.test";

test.describe("kpis", () => {
  test("is defined for a team, given a value, and read by someone who cannot keep it", async ({
    browser,
    viewport,
  }) => {
    const manager = await signedInPhone(browser, AHMAD, viewport);
    const name = `Releases shipped ${Date.now().toString(36)}`;

    await manager.page.goto("/kpis/new");
    await expect(manager.page.getByRole("heading", { name: "New KPI", level: 1 })).toBeVisible();

    await manager.page.getByLabel("About", { exact: true }).selectOption({ label: "Backend" });
    await manager.page.getByLabel("Period", { exact: true }).selectOption({ label: "Monthly" });
    await manager.page.getByLabel("Where the number comes from").selectOption({ label: "Entered by hand" });
    await manager.page.getByLabel("Name", { exact: true }).fill(name);
    await manager.page.getByLabel("Target", { exact: true }).fill("4");
    await manager.page.getByLabel("Unit", { exact: true }).fill("releases");
    await manager.page.getByRole("button", { name: "Create KPI" }).click();

    await expect(manager.page).toHaveURL(/\/kpis\/[0-9a-f-]{36}$/);
    const id = manager.page.url().split("/").pop() ?? "";

    try {
      const tile = manager.page.getByRole("article", { name });
      await expect(tile).toContainText("No data");
      await expect(tile).toContainText("Target: at least 4 releases a month");

      // This month's value, short of the target by more than 10%.
      const record = manager.page.getByRole("region", { name: "Record a value" });
      await record.getByLabel("Value").fill("3");
      await record.getByLabel("Note (optional)").fill("One release slipped");
      await record.getByRole("button", { name: "Save value" }).click();

      await expect(tile).toContainText("3 releases");
      await expect(tile).toContainText("Off track");

      const history = manager.page.getByRole("region", { name: "History" });
      await expect(history.getByRole("row").nth(1)).toContainText("One release slipped");

      // ── Someone who may see KPIs but not keep them ─────────────────────
      const employee = await signedInPhone(browser, SARAH, viewport);

      await employee.page.goto("/kpis");
      await expect(employee.page.getByRole("link", { name: "New KPI" })).toHaveCount(0);

      const theirs = employee.page.getByRole("region", { name: "Teams" }).getByRole("article", { name });
      await expect(theirs).toContainText("Off track");

      await theirs.getByRole("link", { name }).click();
      await expect(employee.page).toHaveURL(new RegExp(`/kpis/${id}$`));
      await expect(employee.page.getByRole("region", { name: "Record a value" })).toHaveCount(0);
      await expect(employee.page.getByRole("button", { name: "Archive" })).toHaveCount(0);
    } finally {
      await manager.page.goto(`/kpis/${id}`);
      await manager.page.getByRole("button", { name: "Archive", exact: true }).click();
      await manager.page.getByRole("button", { name: "Yes, archive" }).click();
      await expect(manager.page).toHaveURL(/\/kpis$/);
      await expect(manager.page.getByText(name)).toHaveCount(0);
    }
  });
});

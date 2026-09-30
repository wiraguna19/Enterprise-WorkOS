import { expect } from "@playwright/test";
import { call } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 11 — "Bulk: select five items, assign and set due date".
 *
 * The five live in a project made for this run, so the browse list filtered to
 * it holds exactly them: no other flow's litter can push one onto a second
 * page, and "select all on this page" would mean something different on a
 * shared project. They are ticked one by one anyway, because that is the
 * control the flow names; the page-wide box is asserted separately.
 *
 * The data is read back per item, as the flow's rule says: an assignee on
 * each, the right day on each. A bar that reported "5 items" over a request
 * that changed four is the failure a screenshot cannot see.
 */
const AHMAD = "ahmad@acme.test";
const SARAH = "sarah@acme.test";

type Person = { id: string; name: string | null; email: string | null };
type WorkItem = {
  reference: string;
  due_at: string | null;
  assignees?: Array<{ membership_id: string; role: string }>;
};

test.describe("bulk changes", () => {
  test("five items are selected, assigned and given a due date at once", async ({ browser, viewport }) => {
    const { page, session } = await signedInPhone(browser, AHMAD, viewport);

    const key = `B${Date.now().toString(36).toUpperCase().slice(-6)}`;
    const project = await call<{ id: string }>(session, "/projects", {
      method: "POST",
      body: { key, name: `E2E bulk ${key}` },
    });

    const references: string[] = [];

    for (let n = 1; n <= 5; n++) {
      const item = await call<WorkItem>(session, "/work-items", {
        method: "POST",
        body: { title: `E2E bulk item ${n}`, type: "task", project_id: project.id },
      });

      references.push(item.reference);
    }

    const people = await call<Person[]>(session, "/people?limit=100");
    const sarah = people.find((person) => person.email === SARAH);

    if (!sarah?.name) throw new Error("The seeded assignee is not there, or has no name to pick.");

    await page.goto(`/work?project=${project.id}`);

    for (const reference of references) {
      await page.getByRole("checkbox", { name: `Select ${reference}`, exact: true }).check();
    }

    const bar = page.getByRole("region", { name: "Change selected" });

    await expect(bar).toContainText("5 selected");

    // The page-wide box reads the selection back: five of five is "all".
    await expect(page.getByRole("checkbox", { name: "Select all on this page" })).toBeChecked();

    await bar.getByLabel("Assign to").selectOption({ label: sarah.name });
    await bar.getByRole("button", { name: "Assign" }).click();

    // Everything succeeded, so nothing is left selected and the bar goes.
    await expect(bar).toBeHidden();

    // Select again for the second change — the page-wide box this time.
    await page.getByRole("checkbox", { name: "Select all on this page" }).check();
    await expect(bar).toContainText("5 selected");

    const due = inDays(12);

    await bar.getByLabel("Due date").fill(due);
    await bar.getByRole("button", { name: "Set due date" }).click();
    await expect(bar).toBeHidden();

    // ── The data, per item ───────────────────────────────────────────────
    for (const reference of references) {
      const item = await call<WorkItem>(session, `/work-items/${reference}`);
      const holder = item.assignees?.find((a) => a.role === "assignee");

      expect(holder?.membership_id, `${reference} was not given to Sarah.`).toBe(sarah.id);
      expect(
        item.due_at?.slice(0, 10),
        `${reference} is not due on ${due} — the bulk change skipped it or shifted the day.`,
      ).toBe(due);
    }
  });
});

/** A plain date, N days out, as an `<input type="date">` speaks it. */
function inDays(days: number): string {
  const date = new Date();

  date.setDate(date.getDate() + days);

  return date.toISOString().slice(0, 10);
}

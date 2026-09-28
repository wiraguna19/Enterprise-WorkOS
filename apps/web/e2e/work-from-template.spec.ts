import { expect } from "@playwright/test";
import { call } from "./support/api";
import { test, signedInPhone } from "./support/auth";
import { answerRequiredFields } from "./support/flows";

/**
 * Not one of docs/11 §4's fifteen flows: templates arrived in Phase 6
 * (ADR 0047), after that list was written. It sits beside flow 4 because it is
 * flow 4 with the form filled in for you.
 *
 * A template creates nothing (ADR 0047): it is a link that opens the ordinary
 * form already filled in, and the person submits that form. So the flow has
 * two halves that can each break without the other noticing — the prefill
 * (the page reads `?template=` and lays the fields over a blank form) and the
 * submit (what was prefilled actually reaches `POST /work-items`). The data
 * check at the end is the second half; a screenshot only vouches for the first.
 *
 * The template is written by an administrator through the API, because writing
 * templates is the settings screen's flow, not this one. It is stamped, so a
 * rerun never picks up the previous run's template by name, and deleted in a
 * `finally`, so the picker does not grow a line per run.
 */
const RINA = "rina@acme.test";
const SARAH = "sarah@acme.test";

type Template = { id: string; name: string };
type WorkItem = { reference: string; title: string; type: string; priority: string };

test.describe("templates", () => {
  test("an employee starts from a template and the item keeps what it filled in", async ({
    browser,
    viewport,
  }) => {
    const stamp = Date.now().toString(36);
    const admin = (await signedInPhone(browser, RINA, viewport)).session;

    // No trailing spaces anywhere: TrimStrings would remove them on the way
    // in, and the equality below would fail over whitespace.
    const title = `E2E incident follow-up ${stamp}`;

    const template = await call<Template>(admin, "/work-item-templates", {
      method: "POST",
      body: {
        name: `E2E template ${stamp}`,
        purpose: "Planted by the end-to-end suite.",
        fields: {
          type: "task",
          priority: "urgent",
          title,
          description: "Filled in from a template by the end-to-end suite.",
        },
      },
    });

    try {
      const { page, session: sarah } = await signedInPhone(browser, SARAH, viewport);

      // From My Work, the way a person without a board open would start.
      await page.goto("/work");
      await page.getByRole("link", { name: "New work item", exact: true }).click();
      await expect(page).toHaveURL(/\/work\/new$/);

      await page.getByRole("link", { name: new RegExp(template.name) }).click();
      await expect(page).toHaveURL(new RegExp(`/work/new\\?template=${template.id}$`));

      // The prefill, as the person sees it before touching anything.
      await expect(page.getByRole("status").filter({ hasText: "Filled in from" })).toContainText(
        template.name,
      );
      await expect(page.getByLabel("Title")).toHaveValue(title);
      await expect(page.getByLabel("Priority")).toHaveValue("urgent");
      await expect(page.getByLabel("Type")).toHaveValue("task");

      // Whatever the organization requires besides — the template does not
      // answer custom fields it was not written with, and this flow is not
      // about them.
      await answerRequiredFields(page, sarah);

      await page.getByRole("button", { name: "Create work item" }).click();
      await expect(page).toHaveURL(/\/work\/[A-Z]+-\d+$/);

      const reference = page.url().split("/").pop() ?? "";
      const created = await call<WorkItem>(sarah, `/work-items/${reference}`);

      expect(created.title).toBe(title);
      expect(
        created.priority,
        "The form showed the template's priority and the item did not keep it — "
          + "the prefill reached the screen but not the payload.",
      ).toBe("urgent");
      expect(created.type).toBe("task");
    } finally {
      await call(admin, `/work-item-templates/${template.id}`, { method: "DELETE" }).catch(
        () => undefined,
      );
    }
  });
});

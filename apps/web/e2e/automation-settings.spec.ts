import { expect } from "@playwright/test";
import { call } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * The settings area, opened — which is the only thing that finds what is wrong
 * with it.
 *
 * Every screen under `/settings` passed `tsc`, `eslint`, PHPStan, Pint, 428
 * tests and `EveryEndpointIsReachableTest` while `/settings/notifications` was
 * throwing at render. Twice, in fact, for two unrelated reasons: an object
 * response typed as an array, and a Server Component handing a FUNCTION to a
 * client component. Neither is visible to a type-checker — the second is a
 * legal prop type — and the reachability guard says what it can see in its own
 * docblock: whether something CALLS the endpoint, never whether the call works.
 *
 * So the first test here is deliberately shallow and covers every screen: each
 * one renders its own heading, and none of them is the error boundary. That is
 * the whole class, locked.
 *
 * The second test is the one the harness keeps re-learning: **sign in as the
 * person with the fewest rights.** A screen gated only by a nav entry looks
 * finished until somebody types the URL.
 */
const RINA = "rina@acme.test"; // Organization Admin — workflow.manage
const TONO = "tono@acme.test"; // Viewer — workflow.view, and nothing else

type Rule = {
  id: string;
  name: string;
  trigger: string;
  conditions: Record<string, unknown>;
  actions: Array<{ type: string; with?: Record<string, unknown> }>;
  is_active: boolean;
};

const BOUNDARY = "This page could not be loaded. The problem has been logged.";

test.describe("settings", () => {
  test("an administrator reaches every settings screen, and writes a rule", async ({
    browser,
    viewport,
  }) => {
    const screen = await signedInPhone(browser, RINA, viewport);
    const page = screen.page;
    const rina = screen.session;

    // Reached through the interface, never by URL. A settings index whose
    // entries lead nowhere would pass a test that navigates — which is the
    // shape of defect this area has already had twice.
    await page.goto("/settings");

    // Scoped to `main`. The header's notification bell is a link whose
    // accessible name is also "Notifications", it sits above the index in the
    // DOM, and the first run of this spec clicked it and landed on the Inbox —
    // where the assertion then failed against a page that was working
    // perfectly. Two controls can share a name legitimately; the page's own
    // region is what separates them.
    const index = page.getByRole("main");

    for (const [entry, heading] of [
      ["Notifications", "Notifications"],
      ["Workflows", "Workflows"],
      ["Automation rules", "Automation rules"],
    ] as const) {
      await index.getByRole("link", { name: entry, exact: true }).click();

      await expect(page.getByRole("heading", { name: heading, level: 1 })).toBeVisible();

      // The assertion the last two months of this area needed. A screen that
      // throws still renders the shell, the nav and the page title area — so
      // "the heading is visible" alone would have passed while the settings
      // page was an error boundary.
      await expect(page.getByText(BOUNDARY)).toBeHidden();

      await page.goBack();
    }

    // ── Writing a rule, through the builder ────────────────────────────────
    const name = `E2E rule ${Date.now()}`;

    await index.getByRole("link", { name: "Automation rules", exact: true }).click();
    await page.getByRole("link", { name: "New rule", exact: true }).click();

    await page.getByLabel("Name").fill(name);
    await page.getByLabel("Description").fill("Written by the end-to-end suite.");
    await page.getByLabel("Runs when").selectOption("work_item.created");

    await page.getByRole("button", { name: "Add a condition" }).click();
    await page.getByLabel("Condition 1 field").selectOption("priority");
    await page.getByLabel("Condition 1 comparison").selectOption("eq");
    await page.getByLabel("Condition 1 value").selectOption("urgent");

    // Every option offered here came from `/workflow-vocabulary`, which derives
    // it from the classes that run it. A builder offering an audience the
    // dispatcher cannot resolve notifies nobody, and a rule that notifies
    // nobody looks exactly like a rule that works.
    await page.getByRole("checkbox", { name: "reviewer" }).check();

    // What will be stored, shown before it is stored — the same assertion the
    // recurrence picker earns: a form that displays one thing and sends
    // another is the failure a picker has and a text box does not.
    await expect(page.getByText('"field": "priority"')).toBeVisible();

    await page.getByRole("button", { name: "Create the rule" }).click();

    await expect(page).toHaveURL(/\/settings\/rules$/);

    // ── What the server actually stored ───────────────────────────────────
    const rules = await call<Rule[]>(rina, "/workflow-rules");
    const created = rules.find((rule) => rule.name === name);

    expect(
      created,
      "The form navigated without an error and the rule is not in the API's list.",
    ).toBeDefined();

    expect(created?.trigger).toBe("work_item.created");
    expect(created?.conditions).toEqual({
      all: [{ field: "priority", op: "eq", value: "urgent" }],
    });
    expect(created?.actions?.[0]?.type).toBe("notify");
    expect(created?.actions?.[0]?.with?.to).toEqual(["assignee", "reviewer"]);

    // ── Switched off, and confirmed where it counts ───────────────────────
    const row = page.locator("section").filter({ hasText: name });

    await row.getByRole("button", { name: "Switch off" }).click();
    await row.getByRole("button", { name: "Switch it off" }).click();

    // The screen first: `click()` resolves when the click is dispatched, not
    // when the Server Action it starts has finished, and reading the API on the
    // next line races the write.
    await expect(row.getByRole("button", { name: "Switch on" })).toBeVisible();

    const after = await call<Rule[]>(rina, "/workflow-rules");

    expect(
      after.find((rule) => rule.id === created?.id)?.is_active,
      "The interface shows the rule stopped and the engine still has it active, "
        + "which is the worst lie this screen could tell.",
    ).toBe(false);
  });

  test("a viewer sees what the automation does and cannot touch it", async ({
    browser,
    viewport,
  }) => {
    const screen = await signedInPhone(browser, TONO, viewport);
    const page = screen.page;

    await page.goto("/settings");
    await page.getByRole("main").getByRole("link", { name: "Automation rules", exact: true }).click();

    // What the rules ARE is configuration anybody working inside them benefits
    // from seeing, so the list itself is not hidden.
    await expect(page.getByRole("heading", { name: "Automation rules" })).toBeVisible();
    await expect(page.getByText(BOUNDARY)).toBeHidden();
    await expect(page.getByText("Open a review when work is submitted")).toBeVisible();

    // What they DID names subjects a viewer may not be entitled to, and writing
    // changes what the product does for everybody. Absent, not disabled: a
    // control that opens a screen which then refuses is worse than one that was
    // never offered.
    await expect(page.getByRole("link", { name: "New rule", exact: true })).toBeHidden();
    await expect(page.getByRole("link", { name: "Edit", exact: true })).toBeHidden();
    await expect(page.getByRole("link", { name: "Why it did or did not fire" })).toBeHidden();
    await expect(page.getByRole("button", { name: "Switch off" })).toBeHidden();

    // The one place a URL is typed on purpose: the question IS what happens to
    // somebody who has one. 403 is folded into 404 — a rule's run log you may
    // not read is one whose existence you should not be able to confirm.
    await page.goto("/settings/rules/01900021-0000-7000-8000-000000000001");

    await expect(page.getByText(/could not be found|404/i).first()).toBeVisible();

    // And the graph editor, which is `workflow.manage` at the route as well as
    // in the interface.
    await page.goto("/settings/workflows");
    await expect(page.getByRole("link", { name: "Edit", exact: true })).toBeHidden();
  });
});

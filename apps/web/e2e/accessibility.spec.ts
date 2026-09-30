import { expect } from "@playwright/test";
import { accessibilityReport } from "./support/a11y";
import { call } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * Every main screen, checked by axe (docs/11 §4, support/a11y.ts).
 *
 * One spec that walks the screens rather than a line added to each flow: the
 * flows are about what a person DOES, and an accessibility failure on a screen
 * is the same failure whichever flow happened to pass through it. Walking
 * them here reports each screen once, by name.
 *
 * Signed in as a manager, because a manager's screens carry the most controls
 * — the bulk bar, the milestone form, the settings entries — and a control is
 * where these failures live. Both viewports: the phone has its own navigation
 * and its own agenda, and they are different markup.
 *
 * The screens are opened by URL on purpose. Reaching them by clicking is what
 * the flows test; this asks only what each one is once it is there.
 */
const AHMAD = "ahmad@acme.test";

test.describe("accessibility", () => {
  test("the main screens have no serious or critical violations", async ({ browser, viewport }) => {
    test.setTimeout(120_000);

    const { page, session } = await signedInPhone(browser, AHMAD, viewport);

    const [item] = await call<Array<{ reference: string }>>(session, "/work-items?limit=1");

    if (!item) throw new Error("Ahmad can see no work at all; the seed is not what this expects.");

    const screens: Array<[string, string]> = [
      ["Home", "/"],
      ["My Work", "/my-work"],
      ["Inbox", "/inbox"],
      ["Work", "/work"],
      ["New work item", "/work/new"],
      ["A work item", `/work/${item.reference}`],
      ["Projects", "/projects"],
      ["Project overview", "/projects/ENG/overview"],
      ["Project board", "/projects/ENG/board"],
      ["Project settings", "/projects/ENG/settings"],
      ["Calendar", "/calendar"],
      ["Timesheet", "/time"],
      ["People", "/people"],
      ["Teams", "/teams"],
      ["Settings", "/settings"],
      ["Sessions", "/settings/sessions"],
    ];

    // Every screen, then one assertion: the first run of a check like this
    // finds a handful of screens at once, and fixing them one rerun at a time
    // is a rerun per screen.
    const failures: string[] = [];

    for (const [screen, path] of screens) {
      await page.goto(path);

      // Not the error boundary: axe would happily pass a page that threw,
      // because the boundary's own markup is accessible (e2e_harness).
      await expect(
        page.getByText("This page could not be loaded. The problem has been logged."),
        `${screen} (${path}) rendered the error boundary.`,
      ).toBeHidden();

      const report = await accessibilityReport(page);

      if (report.length > 0) failures.push(`${screen} (${path}):\n  ${report.join("\n  ")}`);
    }

    expect(failures, `Accessibility violations:\n\n${failures.join("\n\n")}`).toEqual([]);
  });
});

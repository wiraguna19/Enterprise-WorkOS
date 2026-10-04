import { expect } from "@playwright/test";
import { call, type Session } from "./support/api";
import { test, signedInPhone } from "./support/auth";
import { forwardMoveTo } from "./support/flows";

/**
 * The Flow page's drill-throughs open onto the figure they were clicked from.
 *
 * Not one of docs/11 §4's fifteen flows. It is here because the late rate and
 * each department row linked to the completions list with a filter the list
 * page then dropped: both opened the whole window under a figure that counted
 * only part of it, and nothing noticed, because the list looked right — it was
 * a real list of real completions, just not the ones the number was about.
 *
 * The data is arranged, not found. The demo seed has no transition into Done at
 * all, so the first version of this spec skipped on every run and proved
 * nothing. Two requests are completed here — one past its due date, one before
 * it — so the late list is guaranteed to be SHORTER than the whole window, and
 * a page that ignores the filter fails instead of passing by coincidence.
 *
 * Requests, because the request workflow is Submitted → Triaged → Resolved with
 * no guards: the task workflow runs through a review this spec is not about.
 * In ENG, because it has a department behind it.
 *
 * Counts are compared with the API asked the same question, never with a
 * number written into this file.
 *
 * Rina, because org_admin holds `report.view` on every build.
 */
const RINA = "rina@acme.test";

type Flow = {
  from: string;
  to: string;
  departments: Array<{ department_id: string | null; name: string }>;
};

type Completions = Array<{ id: string }>;

const DAY = 24 * 60 * 60 * 1000;

test.describe("flow drill-throughs", () => {
  test("the late rate and a department row each open their own completions", async ({
    browser,
    viewport,
  }) => {
    test.slow();

    const { page, session } = await signedInPhone(browser, RINA, viewport);

    const projects = await call<Array<{ id: string; key: string }>>(session, "/projects?limit=200");
    const eng = projects.find((project) => project.key === "ENG");

    expect(eng, "The seeded project ENG is not there.").toBeDefined();

    const stamp = Date.now();
    await completeRequest(session, eng!.id, `E2E late request ${stamp}`, -2);
    await completeRequest(session, eng!.id, `E2E on-time request ${stamp}`, 7);

    const flow = await call<Flow>(session, "/insights/flow");
    const window = `from=${flow.from}&to=${flow.to}`;

    const all = await call<Completions>(session, `/insights/flow/items?${window}`);
    const late = await call<Completions>(session, `/insights/flow/items?${window}&late=1`);

    // The arrangement itself, checked before the screen is: if these fail the
    // problem is the data, not the page.
    expect(late.length).toBeGreaterThan(0);
    expect(late.length).toBeLessThan(all.length);

    const listed = page
      .getByRole("region", { name: "Completed in this window" })
      .locator("tbody tr");

    // The late rate, reached by clicking it, so the link and the page that
    // reads it are both under test.
    await page.goto("/reports");
    await page
      .getByRole("region", { name: "Headline figures" })
      .locator(`a[href*="late=1"]`)
      .click();

    await expect(page).toHaveURL(/late=1/);
    await expect(page.getByRole("heading", { name: "Finished late", level: 1 })).toBeVisible();
    await expect(listed).toHaveCount(late.length);

    // A department row: Engineering, where both requests landed.
    const department = flow.departments.find((row) => row.department_id !== null);

    expect(department, "ENG's completions should put a department on the Flow page.").toBeDefined();

    const inDepartment = await call<Completions>(
      session,
      `/insights/flow/items?${window}&department_id=${department!.department_id}`,
    );

    await page.goto("/reports");
    // Inside the panel: a team in the sidebar may share a department's name.
    await page
      .getByRole("region", { name: "Where it was delivered" })
      .getByRole("link", { name: department!.name, exact: true })
      .click();

    await expect(page).toHaveURL(/department_id=/);
    await expect(listed).toHaveCount(inDepartment.length);
  });
});

/**
 * Create a request due `dueInDays` from now and walk it to Done.
 *
 * Each move is the one the API offers, not one written down here, so an edited
 * request workflow moves this helper rather than breaking it.
 */
async function completeRequest(
  session: Session,
  projectId: string,
  title: string,
  dueInDays: number,
): Promise<void> {
  const item = await call<{ reference: string }>(session, "/work-items", {
    method: "POST",
    body: {
      title,
      project_id: projectId,
      type: "request",
      due_at: new Date(Date.now() + dueInDays * DAY).toISOString().slice(0, 10),
    },
  });

  for (const category of ["in_progress", "done"]) {
    const move = await forwardMoveTo(session, item.reference, category);

    if (!move) {
      throw new Error(
        `The request workflow offers no move into "${category}" for ${item.reference}, `
          + "so this spec cannot complete the work it measures.",
      );
    }

    await call(session, `/work-items/${item.reference}/transition`, {
      method: "POST",
      body: {
        to_state_id: move.to_state.id,
        ...(move.requires_comment ? { comment: "Completed by the flow drill-through spec." } : {}),
      },
    });
  }
}

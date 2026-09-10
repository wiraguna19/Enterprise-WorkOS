import { expect, test, type Page } from "@playwright/test";
import { call, type Session } from "./support/api";
import { signedInPhone } from "./support/auth";
import { forwardMoveTo } from "./support/flows";

/**
 * docs/11 §4, flow 13 — "Dashboard: manager sees the overdue item and the
 * over-committed person".
 *
 * The flow is written now rather than in Phase 6 because until this commit its
 * second half had nowhere to go. The capacity block showed a bar; Phase 6's own
 * first house rule says a number must be able to show its work; and the
 * endpoint that explains the number had no caller for a phase. **A rule with a
 * standing counter-example in the product is not a rule**, so this flow asserts
 * the drill-through, not just the bar.
 *
 * Everything is arranged so the manager's home has something to say. A
 * dashboard test against whatever the seed happens to contain today asserts the
 * seed, and passes on a home screen that renders nothing at all.
 */
const AHMAD = "ahmad@acme.test";
const SARAH = "sarah@acme.test";

type WorkItem = {
  id: string;
  reference: string;
  title: string;
  due_at: string | null;
  state: { id: string; category: string } | null;
};

type Workload = {
  membership_id: string;
  week_start: string;
  committed_hours: number;
  capacity_hours: number;
  item_count: number;
};

type WorkloadItem = {
  reference: string;
  share_hours: number | null;
};

test.describe("the manager's dashboard", () => {
  test("shows what is overdue and who is over-committed, and both open", async ({
    browser,
    viewport,
  }) => {
    const managersScreen = await signedInPhone(browser, AHMAD, viewport);
    const employeesScreen = await signedInPhone(browser, SARAH, viewport);

    const ahmad = managersScreen.session;
    const sarah = employeesScreen.session;
    const page = managersScreen.page;

    const ahmadId = await membershipOf(ahmad);
    const sarahId = await membershipOf(sarah);

    const project = await call<{ id: string }>(ahmad, "/projects/ENG");

    // ── Something overdue, on the manager's OWN plate ──────────────────────
    //
    // His own, because the "needs attention" block on this screen is about the
    // reader — docs/08 §3 is explicit that a manager with a team still has
    // their own overdue work, and a manager who only ever sees other people's
    // is a manager whose own work rots.
    const overdue = await call<WorkItem>(ahmad, "/work-items", {
      method: "POST",
      body: {
        title: `E2E overdue ${Date.now()}`,
        type: "task",
        project_id: project.id,
        assignee_id: ahmadId,
        due_at: daysFromNow(-3),
      },
    });

    // Out of the backlog for the same reason as below, and for one of its own:
    // work nobody has agreed to start yet is not late, it is unscheduled, and a
    // "needs attention" block that says otherwise trains people to ignore it.
    await commit(ahmad, overdue.reference);

    // ── And somebody over their capacity, this week ────────────────────────
    //
    // Loaded past capacity deliberately rather than hopefully: the bar is only
    // interesting when it says "over", and an arrangement that lands at 60%
    // would make the assertion below pass against a product that had stopped
    // computing anything.
    const before = await call<Workload>(ahmad, `/people/${sarahId}/workload`);
    const needed = before.capacity_hours - before.committed_hours + 8;

    const loaded = await call<WorkItem>(ahmad, "/work-items", {
      method: "POST",
      body: {
        title: `E2E capacity ${Date.now()}`,
        type: "task",
        project_id: project.id,
        assignee_id: sarahId,
        estimate_hours: Math.max(needed, 8),
        // Starts and ends today, so the whole estimate lands in the week the
        // bar is showing. Spanning the item to "today + 2" was the first
        // version and is a weekend bug waiting: run it on a Friday and two
        // thirds of the hours go into next week, leaving this flow asserting
        // over-commitment against a person who is not.
        start_date: today(),
        due_at: endOfToday(),
      },
    });

    // Moved out of the backlog, or it counts for nothing.
    //
    // `StateCategory::COMMITTED` is `todo, in_progress, in_review`, and every
    // new item starts in Backlog — so the first version of this arranged an
    // item worth fourteen hours and changed the figure by zero. **Committed
    // does not mean assigned; it means the work has been agreed to start.**
    await commit(ahmad, loaded.reference);

    const after = await call<Workload>(ahmad, `/people/${sarahId}/workload`);

    expect(
      after.committed_hours,
      "The arrangement did not put Sarah over her capacity, so the rest of this "
        + "flow would be checking an ordinary bar. Work lands in a week through its "
        + "start and due dates — an item carrying neither is committed and unplaced.",
    ).toBeGreaterThan(after.capacity_hours);

    // ── The manager opens his home ─────────────────────────────────────────
    await page.goto("/");

    // ── What is overdue ───────────────────────────────────────────────────
    //
    // The SECTION, not the arranged row. Each section renders `items.slice(0, 5)`
    // and every run of this flow leaves another overdue item on Ahmad's plate,
    // so an assertion naming this run's title passes on a fresh database and
    // fails on the sixth run — a test that decays with use, blaming the product
    // for its own litter. The arrangement is still load-bearing: it is what
    // makes the section non-empty whatever the seed contains today, and it is
    // checked where it can be checked exactly, through the API.
    const overdueView = await call<WorkItem[]>(ahmad, "/me/work?view=overdue");

    expect(
      overdueView.map((item) => item.reference),
      "The arranged item is not in the manager's own overdue view, so the screen "
        + "below would be showing somebody else's arrangement.",
    ).toContain(overdue.reference);

    await expect(page.getByLabel("Overdue")).toBeVisible();

    // ── and nothing is named twice ────────────────────────────────────────
    //
    // The invariant rather than one row, because the invariant is what the page
    // states: overdue work is named once, in the more urgent list. "Due today"
    // is `due_at < end of today`, which is EVERY overdue item by definition, so
    // before this commit every late item appeared in both — described in one of
    // them as due today when it had been due since last week. The rule was
    // written for the unaccepted list and applied one section short.
    const overdueRefs = await referencesIn(page, "Overdue");
    const todayRefs = await referencesIn(page, "Due today");

    expect(
      todayRefs.filter((reference) => overdueRefs.includes(reference)),
      "Work that is already overdue is also being listed as due today. A screen "
        + "that says the same thing twice, once wrongly, is a screen people stop "
        + "reading.",
    ).toEqual([]);

    // ── The bar goes to the work behind it ─────────────────────────────────
    //
    // Clicked, not navigated to. The URL can be constructed by hand and would
    // pass while the dashboard offered no way to reach it — which is precisely
    // the shape of the defect this flow is paying off.
    await page
      .getByRole("link", { name: new RegExp(`items behind Sarah`, "i") })
      .click();

    await expect(page).toHaveURL(new RegExp(`/people/${sarahId}/workload`));

    // The item that pushed her over is named, with what it contributed — not
    // its estimate. An item spanning several weeks gives a slice to each, and a
    // list printing estimates would not add up to the bar above it.
    const row = page.locator("li").filter({ hasText: loaded.reference });

    await expect(row).toBeVisible();

    const breakdown = await call<WorkloadItem[]>(
      sarah,
      `/people/${sarahId}/workload/items?week=${after.week_start}`,
    );

    const contribution = breakdown.find((item) => item.reference === loaded.reference);

    expect(
      contribution,
      "The item is on the screen but not in the breakdown the API folded the "
        + "figure from, which means the two disagree about what this week contains.",
    ).toBeDefined();

    await expect(row).toContainText(`${contribution?.share_hours} h`);

    // The list reconciles with the figure out loud. Everything counted and not
    // listed — work this reader may not see, work with no dates — is stated
    // rather than left as an arithmetic error for the reader to find (ADR 0008).
    await expect(page.getByText(/h listed of .* h committed/)).toBeVisible();

    await managersScreen.context.close();
    await employeesScreen.context.close();
  });
});

/**
 * Walk an item out of the backlog into a category the workload counts.
 *
 * Through the graph, in a bounded loop, rather than by naming a state: how many
 * steps Backlog is from Todo belongs to the workflow, and writing "one" here
 * would be the same mistake flow 5 already paid for one step later.
 */
async function commit(session: Session, reference: string): Promise<void> {
  for (let step = 0; step < 4; step++) {
    const now = await call<WorkItem>(session, `/work-items/${reference}`);

    if (COMMITTED.includes(now.state?.category ?? "")) {
      return;
    }

    const forward = await forwardMoveTo(session, reference, "todo")
      ?? await forwardMoveTo(session, reference, "in_progress");

    if (!forward) {
      throw new Error(
        `${reference} is in "${now.state?.category}" and the workflow offers this `
          + "actor no move towards a committed state, so it can never reach the "
          + "workload figure this flow is about.",
      );
    }

    await call(session, `/work-items/${reference}/transition`, {
      method: "POST",
      body: { to_state_id: forward.to_state.id },
    });
  }
}

/** `StateCategory::COMMITTED`, as the API defines it. */
const COMMITTED = ["todo", "in_progress", "in_review"];

/**
 * The references listed under one of Home's section headings.
 *
 * Each section is `aria-labelledby` its own heading, which is what makes the
 * sections addressable at all — and the reason the heading is a real `<h2>`
 * with an id rather than a styled `<div>`.
 */
async function referencesIn(page: Page, heading: string): Promise<string[]> {
  const section = page.getByLabel(heading);

  if (await section.count() === 0) {
    // Not rendered at all, which is what an empty section does here: a heading
    // over nothing is a hole the reader has to work out is not an error.
    return [];
  }

  // Read from the row's own href rather than from a test attribute: the rows
  // are links to `/work/{reference}`, so the identity is already in the markup
  // and the test does not ask the product to carry a hook for it. The section's
  // "See all" link goes to `/my-work?view=…` and is excluded by the same
  // selector.
  const rows = await section.locator('a[href^="/work/"]').all();

  return Promise.all(
    rows.map(async (row) => (await row.getAttribute("href"))?.replace("/work/", "") ?? ""),
  );
}

async function membershipOf(session: Session): Promise<string> {
  const me = await call<{ membership: { id: string } }>(session, "/auth/me");

  return me.membership.id;
}

/** Dates as the API takes them, anchored to the run rather than to a fixture. */
function daysFromNow(days: number): string {
  const date = new Date();

  date.setDate(date.getDate() + days);

  return date.toISOString();
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function endOfToday(): string {
  const date = new Date();

  date.setHours(23, 59, 0, 0);

  return date.toISOString();
}

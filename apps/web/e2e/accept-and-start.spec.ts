import { expect, test } from "@playwright/test";
import { call, eventually, type Session } from "./support/api";
import { signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 5 — "Employee: notification → open My Work → accept →
 * start".
 *
 * The one flow written entirely from the assignee's side, and the reason that
 * matters is measured rather than assumed: the first test in this suite to ask
 * the API as an ordinary employee got a 500 from the people directory
 * (`23ce021`), because every earlier flow signed in as a manager and
 * short-circuited past the branch that was broken.
 *
 * It also exercises the one control the product cannot function without on a
 * phone — the sticky primary action — from the state where its label comes
 * from the ASSIGNMENT rather than the workflow. Acceptance is not a workflow
 * state; it is a property of the assignment, and the button says "Accept"
 * because of who holds the item, not because of where it sits.
 */
const AHMAD = "ahmad@acme.test";
const SARAH = "sarah@acme.test";

type Person = { id: string; name: string | null; email: string | null };
type Project = { id: string; key: string };

type WorkItem = {
  id: string;
  reference: string;
  state: { id: string; category: string } | null;
  assignees?: Array<{ membership_id: string; role: string; accepted: boolean }>;
};

type HistoryEntry = { role: string; accepted_at: string | null };

type Transition = {
  label: string;
  is_escape_hatch: boolean;
  available: boolean;
  requires_comment: boolean;
  to_state: { id: string; category: string };
};

test.describe("work arriving", () => {
  test("a notification leads to the item, which is accepted and started", async ({
    browser,
    viewport,
  }) => {
    const managersScreen = await signedInPhone(browser, AHMAD, viewport);
    const employeesScreen = await signedInPhone(browser, SARAH, viewport);

    const ahmad = managersScreen.session;
    const sarah = employeesScreen.session;
    const page = employeesScreen.page;

    const her = await personByEmail(ahmad, SARAH);

    // Arranged by the MANAGER, through the API: the point of this flow starts
    // at the notification, not at the assignment.
    const item = await assignedTo(ahmad, her.id);

    // ── The notification, which is how she learns of it ────────────────────
    await eventually(
      "the assignee to be notified",
      async () => {
        const rows = await call<Array<{ type: string; subject: { reference: string | null } }>>(
          sarah,
          "/notifications?unread_only=1&limit=50",
        );

        return rows.find(
          (row) => row.type === "work.assigned" && row.subject.reference === item.reference,
        ) ?? null;
      },
      "Assignment notifications are dispatched in-process, not queued.",
    );

    await page.goto("/inbox?tab=activity");

    const row = page.locator("li").filter({ hasText: item.reference });

    await expect(
      row,
      "The inbox does not name the item. Every notification in this product read "
        + "'an item' until `da26a7a`, because the list read a field the API does not "
        + "send — so a row that mentions the reference is the assertion, not the "
        + "presence of a row.",
    ).toBeVisible();

    // ── and it takes her to the work ───────────────────────────────────────
    await row.getByRole("link").first().click();
    await expect(page).toHaveURL(new RegExp(`/work/${item.reference}$`));

    // ── Accept ────────────────────────────────────────────────────────────
    //
    // The sticky bar says why it is offering this, and the label comes from the
    // assignment rather than the workflow graph.
    await expect(page.getByText("This work is assigned to you but not yet acknowledged.")).toBeVisible();

    await page.getByRole("button", { name: "Accept" }).click();

    const accepted = await eventually(
      "the assignment to be acknowledged",
      async () => {
        const history = await call<HistoryEntry[]>(
          sarah,
          `/work-items/${item.reference}/assignments`,
        );

        const held = history.find((entry) => entry.role === "assignee");

        return held?.accepted_at === null || held === undefined ? null : held;
      },
      "Accepting is a synchronous POST. If the row is still unaccepted, either the "
        + "click never reached the server or the API refused it.",
    );

    expect(accepted.accepted_at).not.toBeNull();

    // ── Start ─────────────────────────────────────────────────────────────
    //
    // "Start" is not one click, and assuming it was is what this test got
    // wrong first: the seeded workflow runs Backlog → Todo → In Progress, so
    // the sticky bar's first suggestion moved the item to Todo and the wait for
    // `in_progress` timed out against a product doing exactly the right thing.
    // The trace settled it — the action carried the Todo state's id.
    //
    // So the test does what a person does: take the step the bar suggests,
    // until the work is under way. The number of steps is the WORKFLOW's
    // business, and hardcoding two here would be the same mistake one step
    // later. Each button's name is read from the graph the bar renders from.
    for (let step = 0; step < 4; step++) {
      const { transitions } = await call<{
        current: { id: string; category: string };
        transitions: Transition[];
      }>(sarah, `/work-items/${item.reference}/available-transitions`);

      const before = await call<WorkItem>(sarah, `/work-items/${item.reference}`);

      if (before.state?.category === "in_progress") break;

      const forward = transitions.find((t) => !t.is_escape_hatch && t.available);

      expect(
        forward,
        "The workflow offers no forward move to the person holding the item, so the "
          + "sticky bar has nothing to suggest and the work cannot be started at all.",
      ).toBeDefined();

      expect(
        forward?.requires_comment,
        `"${forward?.label}" asks for a reason, so it is not a one-tap move and the `
          + "bar deliberately disables it — this flow would need the status menu.",
      ).toBe(false);

      await page.getByRole("button", { name: forward?.label ?? "", exact: true }).click();

      await eventually(
        `the move to ${forward?.to_state.category}`,
        async () => {
          const now = await call<WorkItem>(sarah, `/work-items/${item.reference}`);

          return now.state?.id !== before.state?.id ? now : null;
        },
        "Transitions are synchronous. If the state did not change, the workflow "
          + "refused the move and its reason is on the sticky bar.",
      );

      // The page re-renders from the server after each move, and the next
      // button is a different one — waiting for the request to settle before
      // reading the graph again avoids clicking the previous label.
      await page.waitForLoadState("networkidle");
    }

    const started = await call<WorkItem>(sarah, `/work-items/${item.reference}`);

    expect(started.state?.category).toBe("in_progress");

    // The timeline says both things happened, in the product's own words.
    const activity = await call<Array<{ entries: Array<{ verb: string }> }>>(
      sarah,
      `/work-items/${item.reference}/activity`,
    );

    const verbs = activity.flatMap((event) => event.entries.map((entry) => entry.verb));

    expect(
      verbs,
      "The timeline does not record the acceptance, so 'assigned but not started' "
        + "and 'accepted and not started' look identical afterwards — which is the "
        + "distinction the sticky bar is built on.",
    ).toContain("accepted");
  });
});

async function personByEmail(session: Session, email: string): Promise<Person> {
  const people = await call<Person[]>(session, "/people?limit=200");
  const person = people.find((candidate) => candidate.email === email);

  if (!person) {
    throw new Error(`No seeded person with the email ${email}.`);
  }

  return person;
}

async function assignedTo(session: Session, membershipId: string): Promise<WorkItem> {
  const projects = await call<Project[]>(session, "/projects?limit=200");
  const project = projects.find((candidate) => candidate.key === "ENG");

  if (!project) {
    throw new Error("The seeded project ENG is not there, and this flow will not take another.");
  }

  return call<WorkItem>(session, "/work-items", {
    method: "POST",
    body: {
      title: `E2E arriving work ${Date.now()}`,
      project_id: project.id,
      type: "task",
      assignee_id: membershipId,
    },
  });
}

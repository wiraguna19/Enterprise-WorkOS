import { expect, test } from "@playwright/test";
import { call, eventually, type Session } from "./support/api";
import { signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 9 — "Reassignment mid-flight, and the history reads
 * correctly afterwards".
 *
 * The history is the reason this flow is in the fifteen. Handing work on is
 * easy to make look right and easy to record wrongly: the common shape is a
 * client that unassigns and then assigns, which leaves the item briefly owned
 * by nobody and the timeline reading as two unrelated decisions. The API does
 * it in one transaction, and this proves the interface uses that and not the
 * pair.
 *
 * It also covers the second half — taking a role away without giving it to
 * anyone — which was one of the three undo paths this product had no way in
 * for.
 */
const AHMAD = "ahmad@acme.test";
const SARAH = "sarah@acme.test";
const BUDI = "budi@acme.test";

type Person = { id: string; name: string | null; email: string | null };
type Project = { id: string; key: string };

type WorkItem = {
  id: string;
  reference: string;
  assignees?: Array<{ membership_id: string; role: string; assignment_id: string }>;
};

type HistoryEntry = {
  role: string;
  person: string | null;
  assigned_at: string;
  unassigned_at?: string | null;
};

test.describe("handing work on", () => {
  test("an item moves from one person to another, and the history says so", async ({
    browser,
    viewport,
  }) => {
    const managersScreen = await signedInPhone(browser, AHMAD, viewport);
    const ahmad = managersScreen.session;
    const page = managersScreen.page;

    const [sarah, budi] = await Promise.all([
      personByEmail(ahmad, SARAH),
      personByEmail(ahmad, BUDI),
    ]);

    // Arranged through the API, and arranged EXPLICITLY: an item that inherits
    // its assignee from the seed is an item whose starting position can change
    // under the test without anyone editing it.
    const item = await anItemAssignedTo(ahmad, sarah.id);

    expect(
      item.assignees?.find((a) => a.role === "assignee")?.membership_id,
      "The precondition did not take: this flow needs an item that already belongs "
        + "to somebody, because handing on is what it is about.",
    ).toBe(sarah.id);

    const budisName = budi.name ?? "";

    expect(budisName, "The seeded colleague has no name to select by.").not.toBe("");

    // ── Through the interface, from the item ───────────────────────────────
    await page.goto(`/work/${item.reference}`);

    await page.getByRole("button", { name: "Change assignee" }).click();
    // `exact`, because the picker's own accessible names — "Clear assignee",
    // "Stop changing the assignee" — all contain the word. Labels written for
    // a screen reader are labels a locator has to be precise about.
    await page.getByLabel("Assignee", { exact: true }).selectOption({ label: budisName });

    const handedOn = await eventually(
      "the item to change hands",
      async () => {
        const now = await call<WorkItem>(ahmad, `/work-items/${item.reference}`);
        const holder = now.assignees?.find((a) => a.role === "assignee");

        return holder?.membership_id === budi.id ? now : null;
      },
      "Assigning is a synchronous API call, so this is not the queue: either the "
        + "selection never reached the server, or it was refused and the row is "
        + "showing the reason.",
    );

    // ── The history, which is the point of the flow ────────────────────────
    const history = await call<HistoryEntry[]>(
      ahmad,
      `/work-items/${item.reference}/assignments`,
    );

    const assigneeRows = history.filter((row) => row.role === "assignee");

    expect(
      assigneeRows.length,
      "Handing work on left one row, so the record says Budi has always had this "
        + "item and Sarah never did.",
    ).toBe(2);

    const open = assigneeRows.filter((row) => !row.unassigned_at);

    expect(
      open.length,
      "Two people hold the same role at once. Reassigning is meant to close the "
        + "previous row in the same transaction, not add a second holder.",
    ).toBe(1);

    // Exactly one holder, and it is the person the picker chose. Asserting the
    // count alone would pass with the WRONG row left open.
    expect(handedOn.assignees?.find((a) => a.role === "assignee")?.membership_id).toBe(budi.id);

    // The person who lost it is told, because work leaving your list silently
    // is how somebody keeps planning around a task they no longer have.
    const sarahsSession = (await signedInPhone(browser, SARAH, viewport)).session;

    const told = await eventually(
      "the previous holder to be notified",
      async () => {
        const rows = await call<Array<{ type: string; subject: { reference: string | null } }>>(
          sarahsSession,
          "/notifications",
        );

        // Matched on the TYPE as well as the reference: Sarah already has a
        // `work.assigned` for this item from the arrangement, and a `find`
        // that takes whichever row mentions the reference would pass on that
        // one — the same shape as approving the wrong row off a shared queue.
        return rows.find(
          (row) => row.subject.reference === item.reference && row.type === "work.reassigned_away",
        ) ?? null;
      },
      // Looks, rather than guessing. The queue hint that used to be here named
      // the wrong suspect twice: this notification is dispatched in-process,
      // and the row was in the database while this very wait timed out.
      async () => {
        const rows = await call<Array<{ type: string; subject: { reference: string | null } }>>(
          sarahsSession,
          "/notifications?limit=50",
        ).catch((error: unknown) => {
          throw new Error(`the previous holder's inbox could not be read: ${String(error)}`);
        });

        const summary = rows
          .slice(0, 8)
          .map((row) => `${row.type} ${row.subject.reference ?? "(no reference)"}`)
          .join(", ");

        return `Nothing for ${item.reference}. That session's inbox holds ${rows.length} `
          + `row(s): ${summary || "none"}.`;
      },
    );

    expect(told.type).toBe("work.reassigned_away");
  });

  test("a role can be taken away without being given to anyone", async ({ browser, viewport }) => {
    const managersScreen = await signedInPhone(browser, AHMAD, viewport);
    const ahmad = managersScreen.session;
    const page = managersScreen.page;

    const sarah = await personByEmail(ahmad, SARAH);
    const item = await anItemAssignedTo(ahmad, sarah.id);

    await page.goto(`/work/${item.reference}`);

    await page.getByRole("button", { name: "Change assignee" }).click();
    await page.getByRole("button", { name: "Clear assignee" }).click();

    const cleared = await eventually(
      "the item to become unassigned",
      async () => {
        const now = await call<WorkItem>(ahmad, `/work-items/${item.reference}`);

        return now.assignees?.some((a) => a.role === "assignee") ? null : now;
      },
      "Clearing is a synchronous DELETE. If the item still has an assignee, either "
        + "the button did nothing or the API refused it.",
    );

    expect(cleared.assignees?.some((a) => a.role === "assignee")).toBe(false);

    // Closed, not deleted: the record still says who held it and until when.
    const history = await call<HistoryEntry[]>(
      ahmad,
      `/work-items/${item.reference}/assignments`,
    );

    const sarahsRow = history.find((row) => row.role === "assignee");

    expect(
      sarahsRow,
      "The assignment row is gone entirely, so the item now reads as though nobody "
        + "ever held it — an unassignment that erases its own history.",
    ).toBeDefined();

    expect(sarahsRow?.unassigned_at ?? null).not.toBeNull();

    // And the screen says so. Asserted through the control rather than the word
    // "Unassigned", which appears twice on an item nobody has been given and
    // whose reviewer is also empty — the assertion has to name WHICH role it
    // means.
    await page.reload();
    await expect(page.getByRole("button", { name: "Assign assignee" })).toBeVisible();
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

async function anItemAssignedTo(session: Session, membershipId: string): Promise<WorkItem> {
  const projects = await call<Project[]>(session, "/projects?limit=200");
  const project = projects.find((candidate) => candidate.key === "ENG");

  if (!project) {
    throw new Error("The seeded project ENG is not there, and this flow will not take another.");
  }

  return call<WorkItem>(session, "/work-items", {
    method: "POST",
    body: {
      title: `E2E handover ${Date.now()}`,
      project_id: project.id,
      type: "task",
      assignee_id: membershipId,
    },
  });
}

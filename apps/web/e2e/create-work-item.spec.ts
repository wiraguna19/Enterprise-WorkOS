import { expect, test } from "@playwright/test";
import { call, eventually, type Session } from "./support/api";
import { signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 4 — "Manager: create work item with assignee and due date".
 *
 * Written the day the form was: `POST /work-items` had existed since Phase 3
 * with nothing calling it, so **every work item in this product came from the
 * seed or from curl**, and no test had ever made one the way a person does.
 * That is precisely the gap this suite exists for — the API tests for creation
 * have passed the whole time.
 *
 * The second test is not one of the fifteen. It covers editing, which docs/11
 * does not list because the interface for it did not exist when that list was
 * written, and it asserts the half that matters: a save built on a stale
 * version is REFUSED, not merged and not forced. Optimistic locking is the
 * kind of rule that is only ever exercised by accident in production, at which
 * point somebody's afternoon has already been overwritten.
 */
const AHMAD = "ahmad@acme.test";
const SARAH = "sarah@acme.test";

type Person = { id: string; name: string | null; email: string | null };
type Project = { id: string; key: string; name: string };

type WorkItem = {
  id: string;
  reference: string;
  title: string;
  due_at: string | null;
  lock_version: number;
  project: { key: string } | null;
  assignees?: Array<{ membership_id: string; role: string; assignment_id: string }>;
};

test.describe("creating work", () => {
  test("a manager creates an item with an assignee and a due date", async ({
    browser,
    viewport,
  }) => {
    const managersScreen = await signedInPhone(browser, AHMAD, viewport);
    const ahmad = managersScreen.session;

    const project = await projectByKey(ahmad, "ENG");
    const sarah = await personByEmail(ahmad, SARAH);

    // Read from the API, never written as a literal: the seed's people are
    // fixtures, and a test that hardcodes "Sarah Wijaya" fails the day somebody
    // renames a row for an unrelated reason.
    const sarahsName = sarah.name ?? "";

    expect(sarahsName, "The seeded assignee has no name to select by.").not.toBe("");

    // ── Through the interface, from where a person would start ─────────────
    //
    // The board's button rather than the URL, because the button carrying its
    // project is half the design: the form must open with "which project?"
    // already answered.
    const page = managersScreen.page;

    await page.goto(`/projects/${project.key}/board`);
    // `exact`, because an accessible name matches as a SUBSTRING by default and
    // a card on the board happened to be titled "…New work item…". The first
    // run of this test failed on that, which is the locator equivalent of
    // `.first()` on a shared queue: it would have clicked a stranger's card.
    await page.getByRole("link", { name: "New work item", exact: true }).click();

    await expect(page).toHaveURL(new RegExp(`/work/new\\?project=${project.key}$`));

    const title = `E2E rollback plan ${Date.now()}`;
    const due = inDays(9);

    await page.getByLabel("Title").fill(title);
    await page.getByLabel("Description").fill("Created by the end-to-end suite.");
    await page.getByLabel("Priority").selectOption("high");
    await page.getByLabel("Due").fill(due);
    await page.getByLabel("Assignee").selectOption({ label: sarahsName });

    await page.getByRole("button", { name: "Create work item" }).click();

    // The form lands on the thing it made, which is also how this test learns
    // its reference — there is no other way to know it, and inventing one
    // would mean asserting against an item this flow did not create.
    await expect(page).toHaveURL(/\/work\/[A-Z]+-\d+$/);

    const reference = page.url().split("/").pop() ?? "";

    // ── Now the data, which is the part a screenshot cannot vouch for ──────
    const created = await call<WorkItem>(ahmad, `/work-items/${reference}`);

    expect(created.title).toBe(title);
    expect(
      created.project?.key,
      "The item was filed outside the project its form was opened from — the "
        + "`?project=` the board's link carries did not survive to the payload.",
    ).toBe(project.key);

    expect(
      created.due_at?.slice(0, 10),
      "The due date shifted by a day, which is what happens when a date input is "
        + "read in the browser's time zone instead of the item's.",
    ).toBe(due);

    const assignment = created.assignees?.find((a) => a.role === "assignee");

    expect(
      assignment?.membership_id,
      "Nobody holds the item. Assignment happens in the create step (docs/08 §4) — "
        + "if this is null, the form collected an assignee and dropped it.",
    ).toBe(sarah.id);

    // ── And the person it was given to is told ─────────────────────────────
    //
    // The half nobody checks: an assignment that notifies nobody is a task
    // waiting to be discovered by accident.
    const sarahsSession = (await signedInPhone(browser, SARAH, viewport)).session;

    const notification = await eventually(
      "the assignee to be notified",
      async () => {
        const rows = await call<Array<{ type: string; subject: { reference: string | null } }>>(
          sarahsSession,
          "/notifications?unread_only=1",
        );

        // `subject.reference`, which is what the resource emits. The first
        // version of this read `payload.reference` — a field the API has never
        // sent — and so did the inbox screen, which is how this test found a
        // four-phase-old defect by failing for what looked like its own reason.
        return rows.find((row) => row.subject.reference === reference) ?? null;
      },
      // NOT the queue hint, though the first version of this said so:
      // `WorkNotificationSubscriber` listens to a domain event dispatched in
      // the same process as the request. The hint goes and LOOKS, because the
      // database had the row while this wait was timing out — so the useful
      // question is what this session can see, not whether the row exists.
      () => describeInbox(sarahsSession, reference),
    );

    expect(notification.type).toBe("work.assigned");
  });

  test("a correction is saved, and a stale one is refused", async ({ browser, viewport }) => {
    const managersScreen = await signedInPhone(browser, AHMAD, viewport);
    const ahmad = managersScreen.session;
    const page = managersScreen.page;

    const item = await anItem(ahmad, `E2E title before editing ${Date.now()}`);

    // ── The ordinary correction ────────────────────────────────────────────
    await page.goto(`/work/${item.reference}`);
    await page.getByRole("link", { name: "Edit" }).click();

    const corrected = `${item.title} (corrected)`;

    await page.getByLabel("Title").fill(corrected);
    await page.getByRole("button", { name: "Save changes" }).click();

    await expect(page).toHaveURL(new RegExp(`/work/${item.reference}$`));

    const afterEdit = await call<WorkItem>(ahmad, `/work-items/${item.reference}`);

    expect(afterEdit.title).toBe(corrected);
    expect(
      afterEdit.lock_version,
      "The version did not move, so optimistic locking is not protecting anything: "
        + "every subsequent save would look current no matter what it was built on.",
    ).toBeGreaterThan(item.lock_version);

    // ── The stale one ──────────────────────────────────────────────────────
    //
    // The form is opened, and THEN somebody else saves. This is the sequence
    // that cannot be produced on purpose in real life and happens constantly
    // by accident.
    await page.goto(`/work/${item.reference}/edit`);
    await expect(page.getByLabel("Title")).toHaveValue(corrected);

    const theirTitle = `${item.title} (someone else got here first)`;

    await call(ahmad, `/work-items/${item.reference}`, {
      method: "PATCH",
      body: { title: theirTitle },
    });

    await page.getByLabel("Title").fill(`${item.title} (my version)`);
    await page.getByRole("button", { name: "Save changes" }).click();

    // It says what happened, in both versions' terms, and stays on the form.
    // Scoped by its own words, not by `role=alert`: Next renders a permanently
    // present, usually empty route announcer with that role, so every page in
    // this app has an alert on it before anything goes wrong.
    await expect(
      page.getByText(/somebody else saved this item while you had it open/i),
    ).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`/work/${item.reference}/edit$`));

    const afterConflict = await call<WorkItem>(ahmad, `/work-items/${item.reference}`);

    expect(
      afterConflict.title,
      "The stale save went through and overwrote the other edit. This is the exact "
        + "thing lock_version exists to prevent (docs/03 §8) — and the person whose "
        + "work was lost would never find out.",
    ).toBe(theirTitle);
  });
});

/**
 * A project, by key, with no fallback.
 *
 * `?? projects[0]` filed an item in a stranger's project once and died five
 * steps later complaining about an approval roster. Fail where the precondition
 * fails, not where the symptom surfaces.
 */
async function projectByKey(session: Session, key: string): Promise<Project> {
  const projects = await call<Project[]>(session, "/projects?limit=200");
  const project = projects.find((candidate) => candidate.key === key);

  if (!project) {
    throw new Error(
      `The seeded project ${key} is not there. This flow needs it; it does not want `
        + "whichever project happens to be first.",
    );
  }

  return project;
}

async function personByEmail(session: Session, email: string): Promise<Person> {
  const people = await call<Person[]>(session, "/people?limit=200");
  const person = people.find((candidate) => candidate.email === email);

  if (!person) {
    throw new Error(`No seeded person with the email ${email}.`);
  }

  return person;
}

/** Arranged through the API: making the item is not what the second test is about. */
async function anItem(session: Session, title: string): Promise<WorkItem> {
  const project = await projectByKey(session, "ENG");

  return call<WorkItem>(session, "/work-items", {
    method: "POST",
    body: { title, project_id: project.id, type: "task" },
  });
}

/** A plain date, N days out, in the form an `<input type="date">` speaks. */
function inDays(days: number): string {
  const date = new Date();

  date.setDate(date.getDate() + days);

  return date.toISOString().slice(0, 10);
}

/**
 * What the recipient's own session can actually see.
 *
 * Read with the same credentials the wait used, on purpose: "the row is in the
 * table" and "the person can read it" are different claims, and only the second
 * one is what a notification is for.
 */
async function describeInbox(session: Session, reference: string): Promise<string> {
  try {
    const rows = await call<Array<{ type: string; subject: { reference: string | null } }>>(
      session,
      "/notifications?limit=50",
    );

    const summary = rows
      .slice(0, 8)
      .map((row) => `${row.type} ${row.subject.reference ?? "(no reference)"}`)
      .join(", ");

    return `Nothing for ${reference}. That session's own inbox holds ${rows.length} `
      + `row(s): ${summary || "none"}. If the database has the row and this list does `
      + "not, the session is reading as somebody else — check the membership the token "
      + "resolves to before touching the dispatcher.";
  } catch (error) {
    return `The inbox could not be read at all: ${String(error)}`;
  }
}

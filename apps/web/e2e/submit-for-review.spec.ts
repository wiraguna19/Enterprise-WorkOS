import { expect } from "@playwright/test";
import { call, eventually, type Session } from "./support/api";
import { test, signedInPhone } from "./support/auth";
import { forwardMoveTo, moveThroughTheInterface } from "./support/flows";

/**
 * docs/11 §4, flow 7 — "Employee: submit for review", and the half that came
 * after it: taking the submission back.
 *
 * Flow 8 already drives a submission, but from the REVIEWER's side and only as
 * a precondition. This is the submitter's screen: the move that asks for a
 * reason before it will happen, the note that reason becomes, and the queue
 * that note lands in. Withdrawing is here because it is the same person on the
 * same screen a minute later — `permissions.withdraw` had been in every
 * approval payload since Phase 4 with nothing reading it (`fdd8c40`).
 */
const AHMAD = "ahmad@acme.test";
const SARAH = "sarah@acme.test";

type WorkItem = {
  id: string;
  reference: string;
  state: { id: string; category: string; label: string } | null;
};

type Approval = {
  id: string;
  status: string;
  submission_note: string | null;
  /**
   * Only present on the DETAIL endpoint, and named `reviewers` there.
   *
   * The list resource emits this `whenLoaded('approvers')` and the index does
   * not load the relation, so the field is simply absent from a row — not
   * empty, absent. The first version of this read `approval.approvers` off a
   * list row and crashed on `undefined.map`, which is the honest outcome of
   * asserting against a field that was never sent.
   */
  reviewers?: Array<{ membership_id: string; name: string | null }>;
  subject: { reference: string } | null;
};

/**
 * Two paragraphs on purpose.
 *
 * The queue row collapses whitespace to fit a three-line clamp; the detail page
 * keeps what was typed. A single-line note cannot tell those two apart, so it
 * would have passed against a page that silently flattened everything.
 */
const NOTE_FIRST_LINE = "Migration is reversible and the rollback path is in the runbook.";
const NOTE_SECOND_LINE = "Needs deploying before Friday's freeze.";
const NOTE = `${NOTE_FIRST_LINE}\n\n${NOTE_SECOND_LINE}`;

test.describe("submitting work", () => {
  test("a submission carries its note, and can be taken back", async ({ browser, viewport }) => {
    const managersScreen = await signedInPhone(browser, AHMAD, viewport);
    const employeesScreen = await signedInPhone(browser, SARAH, viewport);

    const ahmad = managersScreen.session;
    const sarah = employeesScreen.session;
    const page = employeesScreen.page;

    const reviewer = await membershipOf(ahmad);
    const item = await anItemUnderWay(ahmad, sarah, await membershipOf(sarah), reviewer);

    expect(
      item.state?.category,
      "The item never reached a state work can be submitted from, so this flow "
        + "would be testing the arrangement rather than the submission.",
    ).toBe("in_progress");

    // ── Submit, through whichever control the move requires ────────────────
    const submitMove = await moveThroughTheInterface(page, sarah, item, "in_review", NOTE);

    // Said out loud rather than skipped quietly. If the workflow ever stops
    // asking for a reason on the way into review, the helper above submits with
    // one tap and types nothing — and every assertion below about the note
    // would be checking a field this workflow no longer fills. A green test
    // that quietly stopped covering half its purpose is worse than a red one.
    expect(
      submitMove.requires_comment,
      "This workflow lets work into review without a reason, so the submission "
        + "note this flow is about is never collected. docs/02 §7 expects the edge "
        + "into review to require one.",
    ).toBe(true);

    // ── The approval it opened ────────────────────────────────────────────
    const approval = await eventually(
      "the submission to open an approval",
      async () => {
        const rows = await call<Approval[]>(
          sarah,
          "/me/approvals?role=requester&status=pending&limit=100",
        );

        return rows.find((row) => row.subject?.reference === item.reference) ?? null;
      },
      "Opening an approval on submission is done by the rule engine, which runs on "
        + "the queue: is `php artisan queue:work --queue=default,low` running?",
    );

    // The row says an approval exists; the detail says who is on it.
    const opened = await call<Approval>(sarah, `/approvals/${approval.id}`);

    expect(
      (opened.reviewers ?? []).map((person) => person.membership_id),
      "The approval went to whoever the API fell back to rather than the reviewer "
        + "this item names. Flow 15 was green for months on that fallback.",
    ).toContain(reviewer);

    expect(
      opened.submission_note,
      "The reason typed into the move did not become the submission note, so the "
        + "reviewer opens a queue with nothing to decide from — which is the whole "
        + "argument for the note being on the row (docs/08 §7).",
    ).toBe(NOTE);

    // ── and the page that shows it in full ────────────────────────────────
    //
    // `GET /approvals/{id}` was unreachable until this screen existed, and what
    // it hid was a permission bug that made the SUBMITTER — the person on this
    // page right now — unable to read her own submission. So this reads it as
    // her, not as the reviewer.
    await page.goto("/inbox?tab=waiting");
    await page
      .locator("li")
      .filter({ hasText: item.reference })
      .getByRole("link", { name: "Full submission" })
      .click();

    await expect(page).toHaveURL(new RegExp(`/approvals/${approval.id}$`));

    // Both lines, separately: the note survived with its break rather than
    // arriving as one collapsed paragraph.
    await expect(page.getByText(NOTE_FIRST_LINE)).toBeVisible();
    await expect(page.getByText(NOTE_SECOND_LINE)).toBeVisible();

    // The reviewer is NAMED, not just counted. "1 of 3 approvals" is what the
    // queue row can say; who the three are is why this page exists — and the
    // field carrying them is `reviewers`, which the web app called `approvers`
    // for three phases without ever being wrong out loud, because nothing read
    // it.
    await expect(page.getByRole("heading", { name: "Asked to decide" })).toBeVisible();
    await expect(page.getByText(await nameOf(ahmad))).toBeVisible();

    // ── and the queue she is waiting in ───────────────────────────────────
    await page.goto("/inbox?tab=waiting");

    const row = page.locator("li").filter({ hasText: item.reference });

    await expect(row).toBeVisible();

    // ── Taken back ────────────────────────────────────────────────────────
    await row.getByRole("button", { name: "Withdraw" }).click();
    await row.getByRole("button", { name: "Withdraw it" }).click();

    const withdrawn = await eventually(
      "the approval to be withdrawn",
      async () => {
        const decided = await call<Approval>(sarah, `/approvals/${approval.id}`);

        return decided.status === "withdrawn" ? decided : null;
      },
      "Withdrawing is a synchronous POST. If the status has not moved, either the "
        + "click never reached the server or the API refused it — most likely because "
        + "a reviewer decided in between, which the row says out loud.",
    );

    expect(withdrawn.status).toBe("withdrawn");

    // It leaves the reviewer's queue too. A withdrawal that only the submitter
    // can see is a submission the reviewer keeps looking at.
    const reviewerQueue = await call<Approval[]>(ahmad, "/approvals?role=reviewer&status=pending&limit=100");

    expect(
      reviewerQueue.map((row) => row.id),
      "The withdrawn submission is still in the reviewer's pending queue.",
    ).not.toContain(approval.id);
  });
});

/**
 * `/auth/me`, not `/me`.
 *
 * `/me/work` and `/me/approvals` exist, so "/me" reads like a resource and is
 * not one — the identity endpoint lives under the auth prefix. Written from
 * memory the first time and answered with a 404, which is the right answer:
 * the API refuses to invent a route because a client guessed one.
 */
/** Their display name, as the product shows it. */
async function nameOf(session: Session): Promise<string> {
  const me = await call<{ user: { name: string } }>(session, "/auth/me");

  return me.user.name;
}

async function membershipOf(session: Session): Promise<string> {
  const me = await call<{ membership: { id: string } }>(session, "/auth/me");

  return me.membership.id;
}

/**
 * An item assigned, accepted, and walked forward to somewhere a submission can
 * happen — through the GRAPH, not a hardcoded chain, so a workflow with
 * different states still arrives.
 *
 * The reviewer is named explicitly. Flow 15 was green for months without one,
 * because `CreateApprovalAction` fell through to the project owner and the
 * owner happened to be the person the test expected.
 */
async function anItemUnderWay(
  manager: Session,
  employee: Session,
  assignee: string,
  reviewer: string,
): Promise<WorkItem> {
  const project = await call<{ id: string }>(manager, "/projects/ENG");

  const created = await call<WorkItem>(manager, "/work-items", {
    method: "POST",
    body: {
      title: `E2E submission ${Date.now()}`,
      type: "task",
      project_id: project.id,
      assignee_id: assignee,
      reviewer_id: reviewer,
    },
  });

  await call(employee, `/work-items/${created.reference}/accept`, { method: "POST" });

  for (let step = 0; step < 4; step++) {
    const current = await call<WorkItem>(employee, `/work-items/${created.reference}`);

    if (current.state?.category === "in_progress") return current;

    const forward = await forwardMoveTo(employee, created.reference, "todo")
      ?? await forwardMoveTo(employee, created.reference, "in_progress");

    if (forward === undefined || forward.requires_comment) break;

    await call(employee, `/work-items/${created.reference}/transition`, {
      method: "POST",
      body: { to_state_id: forward.to_state.id },
    });
  }

  return call<WorkItem>(employee, `/work-items/${created.reference}`);
}

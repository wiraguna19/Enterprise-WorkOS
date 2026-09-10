import { expect, test, type Page } from "@playwright/test";
import { call, eventually, QUEUE_HINT, type Session } from "./support/api";
import { signedInPhone } from "./support/auth";
import { moveThroughTheInterface } from "./support/flows";

/**
 * docs/11 §4, flow 8 — "Manager: request changes → employee resubmits →
 * manager approves".
 *
 * The only closed LOOP in this product. Every other flow is a line: work moves
 * forward and stops. This one goes forward, comes back, and goes forward again
 * — and coming back is the half that has never been exercised through the
 * interface, by anything.
 *
 * It is also the only proof ADR 0005's neighbour will ever get. That ADR draws
 * the line between a rejection (the work should not continue, so it is
 * cancelled) and a changes request (the same work, sent back to be finished).
 * The two differ by one word in a listener's lookup table, and nothing outside
 * a unit test has ever checked that the product honours the difference.
 */
const SARAH = "sarah@acme.test";
const AHMAD = "ahmad@acme.test";

type WorkItem = {
  id: string;
  reference: string;
  state: { id: string; key: string; label: string; category: string } | null;
};

// `subject` is nullable: the resource sends null for an approval whose subject
// this reader cannot load. Declaring it non-null is what let `row.subject.reference`
// compile and then throw on the first such row in the queue.
type Approval = {
  id: string;
  status: string;
  subject: { reference: string } | null;
  /** Detail endpoint only, and named `reviewers` there though the relation is `approvers`. */
  reviewers?: Array<{ membership_id: string; name: string | null }>;
};

test.describe("the review loop", () => {
  test("changes are requested, the work comes back, and the second try is approved", async ({
    browser,
    viewport,
  }) => {
    const sarahsScreen = await signedInPhone(browser, SARAH, viewport);
    const ahmadsScreen = await signedInPhone(browser, AHMAD, viewport);

    const sarah = sarahsScreen.session;
    const ahmad = ahmadsScreen.session;

    const item = await anItemReadyToSubmit(sarah, ahmad);
    const started = await call<WorkItem>(sarah, `/work-items/${item.reference}`);

    // ── First submission ───────────────────────────────────────────────────
    await submit(
      sarahsScreen.page,
      sarah,
      started,
      "First pass — the migration and its rollback are both in the branch.",
    );

    const first = await pendingApprovalFor(ahmad, sarah, item.reference);

    // ── Sent back, with a reason ───────────────────────────────────────────
    //
    // The reason is not decoration. The edge out of review requires a comment
    // and the API enforces it again on write, so a "Send back" with an empty
    // box is refused — which is why the form disables its own button until
    // something is typed.
    await ahmadsScreen.page.goto("/inbox");

    const row = ahmadsScreen.page.locator("li").filter({ hasText: item.reference });

    await expect(row).toBeVisible();
    await row.getByRole("button", { name: "Request changes" }).click();
    await row.getByRole("textbox").fill("The migration needs a rollback path before this can ship.");
    await row.getByRole("button", { name: "Send back" }).click();

    const sentBack = await eventually(
      "the decision to be recorded",
      async () => {
        const decided = await call<{ status: string }>(ahmad, `/approvals/${first.id}`);

        return decided.status === "changes_requested" ? decided : null;
      },
      'The decision is a synchronous API call, so this is not the queue: either the '
        + 'click never reached the server, or it was refused and the form is showing '
        + 'the reason.',
    );

    expect(sentBack.status).toBe("changes_requested");

    // ── and the work actually comes back ───────────────────────────────────
    //
    // The point of the whole flow. Before the listener that does this existed,
    // the approval said "changes requested" and the item stayed parked in
    // review — where the only edge back is reviewer-guarded, so the person
    // whose work was bounced could not resume it. The approval said one thing
    // and the board said another, and the board is what people act on.
    const returned = await eventually(
      "the work to come back to its assignee",
      async () => {
        const now = await call<WorkItem>(sarah, `/work-items/${item.reference}`);

        return now.state?.category === "in_progress" ? now : null;
      },
      'The approval was sent back but the work did not move. That is the failure '
        + 'TransitionOnApprovalDecision exists to prevent — and it is deliberately '
        + 'NOT queued, so this is not a worker problem.',
    );

    expect(returned.state?.category).toBe("in_progress");

    // The reviewer's reason carries onto the item rather than being asked for
    // twice — the second copy is the one that ends up empty.
    const history = await call<Array<{ entries: Array<{ verb: string }> }>>(
      sarah,
      `/work-items/${item.reference}/activity`,
    );

    const verbs = history.flatMap((event) => event.entries.map((entry) => entry.verb));

    expect(
      verbs,
      "The timeline does not record that this was sent back, so the assignee has no "
        + "way to see why their work returned.",
    ).toContain("review_changes_requested");

    // ── Second submission, from the same screen as the first ───────────────
    // `returned`, not `started`: the status control is named by the state the
    // item is in NOW, and the round trip through review left it in a different
    // one. That read is already made above, waiting for the work to come back.
    await submit(
      sarahsScreen.page,
      sarah,
      returned,
      "Addressed the review: the index is created concurrently now.",
    );

    const second = await pendingApprovalFor(ahmad, sarah, item.reference);

    expect(
      second.id,
      "Resubmitting reused the resolved approval instead of opening a new one. A "
        + "decision that can be un-made is not a record of anything.",
    ).not.toBe(first.id);

    // ── Approved, and the work moves on ────────────────────────────────────
    await ahmadsScreen.page.goto("/inbox");

    const secondRow = ahmadsScreen.page.locator("li").filter({ hasText: item.reference });

    await expect(secondRow).toBeVisible();
    await secondRow.getByRole("button", { name: "Approve" }).click();

    const approved = await eventually(
      "the approval to move the work on",
      async () => {
        const now = await call<WorkItem>(sarah, `/work-items/${item.reference}`);

        return now.state?.id !== started.state?.id && now.state?.category !== "in_progress"
          ? now
          : null;
      },
      QUEUE_HINT,
    );

    expect(approved.state?.id).not.toBe(started.state?.id);

    await sarahsScreen.context.close();
    await ahmadsScreen.context.close();
  });
});

/**
 * Submit for review from the item's own page, the way a person does.
 *
 * Was three lines and a click on the sticky bar until the edge into review
 * started asking for a reason, at which point the button it clicked became
 * permanently disabled — correctly, and this test would have reported it as a
 * broken submission. The shared helper asks the API which control the move
 * needs and drives that one, so the next edge to grow a requirement moves this
 * test instead of breaking it.
 *
 * The reason differs between the two submissions on purpose: the second is the
 * only evidence a resubmission writes a note of its own rather than inheriting
 * the first one.
 */
async function submit(
  page: Page,
  session: Session,
  item: WorkItem,
  reason: string,
): Promise<void> {
  await moveThroughTheInterface(page, session, item, "in_review", reason);
}

/**
 * The one pending approval for this item, once the rule engine has opened it.
 *
 * Two different failures hide behind "no approval yet" and they are found in
 * completely different places: nothing was created at all (the worker is not
 * running, or no rule matched), or something was created and this reviewer is
 * not on its roster. So the requester's own side is read in the same breath,
 * and the second case throws immediately instead of waiting out a timeout that
 * would blame the queue.
 *
 * The first version of this helper had this paragraph and none of the code —
 * a comment asserting a capability is not evidence of one, which is the exact
 * failure this codebase keeps finding in itself.
 */
async function pendingApprovalFor(
  reviewer: Session,
  requester: Session,
  reference: string,
): Promise<Approval> {
  const reviewerId = await membershipOf(reviewer);

  return eventually(
    `an approval for ${reference}`,
    async () => {
      const [reviewing, requested] = await Promise.all([
        call<Approval[]>(reviewer, "/approvals?role=reviewer&status=pending"),
        call<Approval[]>(requester, "/me/approvals?role=requester&status=pending"),
      ]);

      // `subject?`, because the resource emits null for an approval whose
      // subject this reader cannot load — and one unreadable row in the queue
      // is not a reason for this flow to die with a TypeError instead of
      // waiting for the row it came for.
      const mine = reviewing.find((row) => row.subject?.reference === reference);

      if (mine) {
        return mine;
      }

      const theirs = requested.find((row) => row.subject?.reference === reference);

      if (theirs) {
        // The approval exists and the reviewer's queue does not have it, which
        // is a roster question (ADR 0001) rather than a queue one — no amount
        // of waiting fixes it.
        //
        // So say WHO is on it. The first version of this asserted the diagnosis
        // ("Ahmad is not its reviewer, even though he holds the reviewer role
        // on the item") and named no names, which turned a wrong roster into a
        // guess about which of four things produced it. The detail endpoint
        // answers exactly this, and the requester may read her own submission.
        const opened = await call<Approval>(requester, `/approvals/${theirs.id}`);
        const roster = opened.reviewers ?? [];

        // On the roster, so this is not a roster problem — keep waiting.
        //
        // The two lists above are fetched in one `Promise.all`, and they can
        // straddle the commit: the reviewer's queue answered a moment BEFORE
        // the job's transaction landed and the requester's a moment after. The
        // first version threw here on that two-millisecond window and reported
        // a permanent roster fault, which is how a race gets a wrong name and a
        // real bug gets ignored the next time this message appears.
        //
        // The roster is the authoritative answer to "is this a roster
        // problem". A queue that has not caught up is not one.
        if (roster.some((person) => person.membership_id === reviewerId)) {
          return null;
        }

        throw new Error(
          `An approval for ${reference} exists and is not in the reviewer's queue. `
            + 'It is assigned to: '
            + `${roster.length === 0 ? "nobody" : roster.map((p) => p.name ?? p.membership_id).join(", ")}. `
            + 'The rule resolves its roster from the item\'s reviewer assignments and '
            + 'falls back to the project owner when it finds none — a roster naming '
            + 'the owner means the assignment was not visible when the job ran, and '
            + 'an empty one means the approval can never be decided at all.',
        );
      }

      return null;
    },
    QUEUE_HINT,
  );
}

/**
 * Sarah's work item, in progress, with Ahmad named as its reviewer.
 *
 * The reviewer is assigned explicitly rather than left to the rule's last
 * resort (the project owner). Flow 15 learned that the hard way: it was green
 * only while that owner happened to be the person the test expected.
 */
async function anItemReadyToSubmit(sarah: Session, ahmad: Session): Promise<WorkItem> {
  const project = await call<{ id: string }>(ahmad, "/projects/ENG");

  const created = await call<WorkItem>(ahmad, "/work-items", {
    method: "POST",
    body: {
      title: `E2E review loop ${Date.now()}`,
      type: "task",
      project_id: project.id,
      priority: "medium",
    },
  });

  await call(ahmad, `/work-items/${created.reference}/assign`, {
    method: "POST",
    body: { membership_id: await membershipOf(sarah), role: "assignee" },
  });

  await call(ahmad, `/work-items/${created.reference}/assign`, {
    method: "POST",
    body: { membership_id: await membershipOf(ahmad), role: "reviewer" },
  });

  await call(sarah, `/work-items/${created.reference}/accept`, { method: "POST" });

  // Walked forward from the graph rather than through a hardcoded chain, so a
  // workflow with different states still arrives somewhere a submission can
  // happen.
  for (let step = 0; step < 4; step++) {
    const current = await call<WorkItem>(sarah, `/work-items/${created.reference}`);

    if (current.state?.category === "in_progress") {
      return current;
    }

    const { transitions } = await call<{
      transitions: Array<{
        to_state: { id: string; category: string };
        available: boolean;
        requires_comment: boolean;
      }>;
    }>(sarah, `/work-items/${created.reference}/available-transitions`);

    const forward = transitions.find(
      (transition) =>
        transition.available
        && ! transition.requires_comment
        && ["todo", "in_progress"].includes(transition.to_state.category),
    );

    if (!forward) break;

    await call(sarah, `/work-items/${created.reference}/transition`, {
      method: "POST",
      body: { to_state_id: forward.to_state.id },
    });
  }

  return call<WorkItem>(sarah, `/work-items/${created.reference}`);
}

async function membershipOf(session: Session): Promise<string> {
  const me = await call<{ membership: { id: string } }>(session, "/auth/me");

  return me.membership.id;
}

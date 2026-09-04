import { expect, test } from "@playwright/test";
import { call, eventually, QUEUE_HINT, type Session } from "./support/api";
import { signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 15 — "Mobile: manager approves a submission end to end on a
 * 375px viewport" — which is also the Phase 5 exit criterion, outstanding since
 * the phase shipped.
 *
 * The whole flow runs through the interface at phone size. The API is used only
 * to put a work item in front of Sarah and to read back what actually happened
 * afterwards: a test that drives the API and then checks the API proves the API
 * twice and the product not at all. That distinction is not academic here — the
 * button this flow taps was inert until `6d2d146`, while every API test passed.
 */
const SARAH = "sarah@acme.test";
const AHMAD = "ahmad@acme.test";

type WorkItem = {
  id: string;
  reference: string;
  state: { id: string; label: string; category: string } | null;
};

type Approval = { id: string; status: string; subject: { reference: string } };

test.describe("mobile approval", () => {
  test.skip(({ viewport }) => (viewport?.width ?? 0) > 500, "phone-sized only");

  test("a manager approves a submission end to end", async ({ browser, viewport }) => {
    // One signed-in phone each, reusing a saved session where there is one:
    // sign-in is rate-limited per person and a suite that logs everyone in on
    // every run locks itself out (see support/auth.ts).
    const sarahsPhone = await signedInPhone(browser, SARAH, viewport);
    const ahmadsPhone = await signedInPhone(browser, AHMAD, viewport);

    const page = sarahsPhone.page;
    const inbox = ahmadsPhone.page;
    const sarah = sarahsPhone.session;
    const ahmad = ahmadsPhone.session;

    const item = await arrangeItemInProgress(sarah, ahmad);

    // ── Sarah submits, on her phone ────────────────────────────────────────
    await page.goto(`/work/${item.reference}`);

    // The sticky bar is the whole affordance at this width. Its label comes
    // from the workflow graph, so the assertion is that a forward move is
    // offered — not that it is spelled a particular way.
    const primary = page.getByRole("button", { name: /review/i });
    await expect(primary).toBeEnabled();
    await primary.click();

    await expect(page.getByText(/in review/i).first()).toBeVisible();

    // The state it is in once submitted, kept to compare against later. The
    // assertion after the approval is that the work MOVED — which is
    // workflow-agnostic, where naming the destination state is not.
    const submitted = await call<WorkItem>(sarah, `/work-items/${item.reference}`);

    // ── The rule engine creates the approval ───────────────────────────────
    //
    // Two different failures hide behind "no approval yet", so they are told
    // apart before the timeout rather than after it: nothing was created at all
    // (the worker is not running, or no rule matched), or something was created
    // and this reviewer is not on it (the rule's roster is not who we think).
    const approval = await eventually("the approval to be created", async () => {
      const [reviewing, requested] = await Promise.all([
        call<Approval[]>(ahmad, "/approvals?role=reviewer&status=pending"),
        call<Approval[]>(sarah, "/me/approvals?role=requester&status=pending"),
      ]);

      const mine = reviewing.find((row) => row.subject.reference === item.reference);

      if (mine) {
        return mine;
      }

      const exists = requested.some((row) => row.subject.reference === item.reference);

      if (exists) {
        throw new Error(
          `An approval for ${item.reference} exists, but Ahmad is not its reviewer — `
            + 'even though he was assigned the reviewer role on the item before it '
            + 'was submitted. The rule resolves its roster from the item\'s '
            + 'assignments (ADR 0001), so either that lookup is not seeing the '
            + 'assignment or the rule is configured to resolve reviewers some '
            + 'other way. Either way this is a configuration finding, not a '
            + 'timing one.',
        );
      }

      return null;
    }, QUEUE_HINT);

    // ── Ahmad approves, on his phone ───────────────────────────────────────
    await inbox.goto("/inbox");

    // The ROW for this item, not the first Approve on the page. Ahmad's queue
    // holds whatever else is pending — including submissions left by earlier
    // runs of this flow — and `.first()` quietly approved one of those instead,
    // which then looked like "the decision never landed".
    const row = inbox.locator("li").filter({ hasText: item.reference });

    await expect(row).toBeVisible();
    await row.getByRole("button", { name: "Approve" }).click();

    // ── What the database says, first ──────────────────────────────────────
    //
    // Asked before the screen is asserted on, so the two failures stay
    // separate: a decision that never landed is one finding, and a decision
    // that landed while the queue went on showing the row is a different one.
    // Assert the screen first and both look like "the row is still there".
    const decided = await eventually("the decision to be recorded", async () => {
      const row = await call<{ status: string }>(ahmad, `/approvals/${approval.id}`);

      return row.status === "approved" ? row : null;
    }, 'The decision is a synchronous API call, so this is not the queue: either the '
      + 'Approve click never reached the server, or it was refused and the form is '
      + 'showing the reason.');

    expect(decided.status).toBe("approved");

    // ── and only then, what the screen says ────────────────────────────────
    await expect(
      row,
      'The decision was recorded, but the review queue still lists it. The action '
        + 'revalidates /inbox — if this fails, the queue is not re-rendering after a '
        + 'decision, which is a stale-screen defect and not a test timing problem.',
    ).toBeHidden();

    // The decision moves the work, and that move is a queued rule rather than
    // part of the request — so it is waited for, not asserted immediately.
    //
    // The assertion is that the STATE changed, not that it reached a named one.
    // In the seeded workflow "Approved" is still in the `in_review` category
    // (the category ends at "Completed"), so asserting on the category here
    // would fail while the product behaved correctly — which is exactly what it
    // did on the first run of this line.
    const after = await eventually("the approval to move the work on", async () => {
      const now = await call<WorkItem>(sarah, `/work-items/${item.reference}`);

      return now.state?.id !== submitted.state?.id ? now : null;
    }, QUEUE_HINT);

    expect(after.state?.id).not.toBe(submitted.state?.id);

    await sarahsPhone.context.close();
    await ahmadsPhone.context.close();
  });
});

/**
 * A work item of this test's own, in progress and assigned to Sarah.
 *
 * Created rather than borrowed from the seed: the seeded items are a demo, and
 * a flow that moves one through the workflow leaves the demo in a different
 * state than the next reader expects — and than the run after this one does.
 */
async function arrangeItemInProgress(sarah: Session, ahmad: Session): Promise<WorkItem> {
  // Asked for by key, not found by scanning the directory.
  //
  // The scan ended in `?? projects[0]`, so a missing or invisible ENG did not
  // fail here — it quietly filed the item in whatever project sorted first,
  // owned by somebody else, and the flow then died much later complaining that
  // the approval's roster was wrong. A precondition that cannot be met should
  // fail where it is not met.
  const engineering = await call<{ id: string; key: string }>(ahmad, "/projects/ENG");

  const created = await call<WorkItem>(ahmad, "/work-items", {
    method: "POST",
    body: {
      title: `E2E mobile approval ${Date.now()}`,
      type: "task",
      project_id: engineering.id,
      priority: "medium",
    },
  });

  await call(ahmad, `/work-items/${created.reference}/assign`, {
    method: "POST",
    body: { membership_id: await membershipOf(sarah), role: "assignee" },
  });

  await call(sarah, `/work-items/${created.reference}/accept`, { method: "POST" });

  // Ahmad is named as the reviewer ON THE ITEM, before anything is submitted.
  //
  // Without this the rule falls through to its last resort — the project's
  // owner — and the flow silently becomes a test of who happens to own ENG in
  // the seed rather than of the approval path. That is what broke it: the
  // approval was created, correctly, for a reviewer this test never asked for.
  //
  // Naming him here also exercises the branch the product actually uses
  // (`reviewers: assigned_reviewers`), leaving the owner fallback to the unit
  // tests that are about the fallback.
  await call(ahmad, `/work-items/${created.reference}/assign`, {
    method: "POST",
    body: { membership_id: await membershipOf(ahmad), role: "reviewer" },
  });

  // Walk it forward until it is somewhere a submission can happen. The moves
  // come from the graph rather than from a hardcoded chain, so a workflow with
  // different states still arrives.
  for (let step = 0; step < 4; step++) {
    const current = await call<WorkItem>(sarah, `/work-items/${created.reference}`);

    if (current.state?.category === "in_progress") {
      return current;
    }

    const { transitions } = await call<{ transitions: Array<{ to_state: { id: string; category: string }; available: boolean; requires_comment: boolean }> }>(
      sarah,
      `/work-items/${created.reference}/available-transitions`,
    );

    const forward = transitions.find(
      (transition) =>
        transition.available
        && ! transition.requires_comment
        && ["todo", "in_progress"].includes(transition.to_state.category),
    );

    if (!forward) {
      break;
    }

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

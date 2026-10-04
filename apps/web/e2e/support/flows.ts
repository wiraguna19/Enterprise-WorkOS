import { expect, type Page } from "@playwright/test";
import { call, type Session } from "./api";

/**
 * Moves a person makes on more than one screen.
 *
 * Extracted the day "Submit for review" started asking for a reason: three
 * flows submitted work by clicking the sticky bar's primary button, and all
 * three broke at once, because that button is deliberately DISABLED for a move
 * that needs a comment. The right fix is not three copies of the menu dance —
 * it is one, so the next edge that grows a requirement breaks one place.
 */

export type Transition = {
  id: string;
  label: string;
  available: boolean;
  requires_comment: boolean;
  is_escape_hatch: boolean;
  to_state: { id: string; category: string; label: string };
};

/** The first forward move whose destination is in the category asked for. */
export async function forwardMoveTo(
  session: Session,
  reference: string,
  category: string,
): Promise<Transition | undefined> {
  const { transitions } = await call<{ transitions: Transition[] }>(
    session,
    `/work-items/${reference}/available-transitions`,
  );

  return transitions.find(
    (transition) =>
      transition.available
      && !transition.is_escape_hatch
      && transition.to_state.category === category,
  );
}

/**
 * Make a forward move from the item's own page, through whichever control the
 * workflow requires — one tap on the sticky bar, or the status menu and its
 * reason box.
 *
 * The branch is decided by the API's own answer rather than by the test's
 * belief about the seed, so a workflow edited tomorrow moves the test instead
 * of breaking it. `reason` is only typed when the edge asks for one; a workflow
 * that stops asking silently stops recording it, which is why callers that
 * care assert `requires_comment` themselves.
 *
 * Returns the transition taken, so the caller can assert against the label and
 * destination the server offered rather than ones it hardcoded.
 */
export async function moveThroughTheInterface(
  page: Page,
  session: Session,
  item: { reference: string; state: { label: string } | null },
  category: string,
  reason: string,
): Promise<Transition> {
  const move = await forwardMoveTo(session, item.reference, category);

  expect(
    move,
    `The workflow offers no available forward move into "${category}" for `
      + `${item.reference}, so there is nothing for this flow to drive.`,
  ).toBeDefined();

  const transition = move as Transition;

  await page.goto(`/work/${item.reference}`);

  if (transition.requires_comment) {
    // The menu's trigger is named by the CURRENT state — it is the status
    // control, not a button called "menu" — and the menu itself is named after
    // the item it moves, which is what keeps these two locators apart on a page
    // that also has a board card and a breadcrumb saying the same words.
    await page.getByRole("button", { name: item.state?.label ?? "", exact: true }).click();

    const menu = page.getByRole("menu", { name: `Move ${item.reference}` });

    await menu.getByRole("menuitem", { name: transition.label }).click();

    // Scoped to the prompt, because its confirm button repeats the transition's
    // label and the sticky bar behind it carries the same words — disabled,
    // which is exactly why the move came through the menu. Two buttons, one
    // name; the dialog is what tells them apart.
    const prompt = page.getByRole("dialog", { name: `Move ${item.reference} to ${transition.to_state.label}` });

    await prompt.getByRole("textbox", { name: /why are you moving this/i }).fill(reason);
    await prompt.getByRole("button", { name: transition.label, exact: true }).click();
  } else {
    const primary = page.getByRole("button", { name: transition.label, exact: true });

    await expect(primary).toBeEnabled();
    await primary.click();
  }

  await expect(
    page.getByText(transition.to_state.label, { exact: false }).first(),
  ).toBeVisible();

  return transition;
}

type DeclaredField = {
  label: string;
  type: "text" | "number" | "date" | "select";
  options: string[];
  required: boolean;
  live: boolean;
};

/**
 * Answer every REQUIRED custom field the organization has declared, on a
 * create form that is already open.
 *
 * Read from the API rather than written down, because these are the
 * organization's own fields (ADR 0038) and an administrator adds one whenever
 * they like: the template flow's first run failed on a required "Client" that
 * nobody seeded — somebody had declared it by hand while trying the settings
 * screen. A required field the flow is not about must not decide whether the
 * flow passes; one it IS about is asserted by that flow, not answered here.
 */
export async function answerRequiredFields(page: Page, session: Session): Promise<void> {
  const fields = await call<DeclaredField[]>(session, "/work-items/fields");

  for (const field of fields) {
    if (!field.required || !field.live) continue;

    const input = page.getByLabel(`${field.label} *`, { exact: true });

    if (field.type === "select") {
      await input.selectOption(field.options[0] ?? "");
    } else if ((await input.inputValue()) === "") {
      await input.fill(
        field.type === "number" ? "1" : field.type === "date" ? "2030-01-01" : "E2E",
      );
    }
  }
}

type PendingReview = { id: string; subject: { title: string | null } | null };

/**
 * Settle what earlier runs left in a reviewer's queue.
 *
 * The review queue is oldest-first on purpose — a newest-first queue starves
 * the submission that has waited longest — and both the API page and the inbox
 * show only the front of it. So every run that leaves an approval pending
 * pushes the next run's approval further back, until it is past the inbox's
 * first page and past the API's hundred-row ceiling, and the flow fails saying
 * the reviewer is not on the roster when he simply cannot see the end of his
 * own queue. That is what a day of runs did to Ahmad's.
 *
 * Only approvals on work this suite created are touched — every such item is
 * titled "E2E …" — and approving is the one decision that needs no comment.
 * Seeded approvals, and anything a person made by hand, are left alone.
 */
export async function settleLeftoverReviews(reviewer: Session): Promise<void> {
  for (let round = 0; round < 20; round++) {
    const queue = await call<PendingReview[]>(
      reviewer,
      "/approvals?role=reviewer&status=pending&limit=100",
    );

    const leftovers = queue.filter((row) => row.subject?.title?.startsWith("E2E ") ?? false);

    if (leftovers.length === 0) return;

    let settled = 0;

    for (const row of leftovers) {
      // One that cannot be decided — already settled by a rule, or one this
      // reviewer may not decide — is skipped rather than failing the flow that
      // is only tidying up before it starts.
      await call(reviewer, `/approvals/${row.id}/decide`, {
        method: "POST",
        body: { decision: "approved" },
      })
        .then(() => {
          settled += 1;
        })
        .catch(() => undefined);
    }

    if (settled === 0) return;
  }
}

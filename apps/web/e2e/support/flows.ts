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

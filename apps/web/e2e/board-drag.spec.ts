import { expect, test } from "@playwright/test";
import { call, eventually, type Session } from "./support/api";
import { signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 10 — "Board: drag a card between columns; verify state and
 * activity log".
 *
 * This is the flow that most needs a browser. Every other interaction in the
 * product is a link or a form, and this one is a gesture: nothing below the
 * interface can tell you whether a card can actually be picked up and dropped.
 * The board proved that the hard way — its cards had been `<Link>`s since Phase
 * 3, under a comment describing them as "discrete draggable objects", while
 * `POST /move` and its fractional ordering were implemented, tested and unused.
 *
 * Both ways of moving a card are exercised, because ADR 0012 §4 promises they
 * are one behaviour and not a real path plus a courtesy one.
 *
 * docs/11 asks this flow to verify the activity log as well as the state, and
 * for a while it could not: `activity.view` was a permission with no endpoint
 * behind it. There is one now, so both halves are checked — and the trail is
 * read through the item's page, because a log nobody can see is a log nobody
 * can check.
 */
const AHMAD = "ahmad@acme.test";

type WorkItem = {
  id: string;
  reference: string;
  state: { id: string; key: string; label: string } | null;
};

type Transition = {
  to_state: { id: string; key: string; label: string };
  available: boolean;
  requires_comment: boolean;
};

test.describe("board drag", () => {
  // The board is a horizontal surface with a drag, and ADR 0012 §4 deliberately
  // does not give it one on touch. Nothing to prove at 375px.
  test.skip(({ viewport }) => (viewport?.width ?? 0) < 700, "desktop only, by decision");

  test("a card dragged to another column changes state", async ({ browser, viewport }) => {
    const { page, session, context } = await signedInPhone(browser, AHMAD, viewport);

    const { item, destination, fromKey } = await aCardWithSomewhereToGo(session);

    await page.goto("/projects/ENG/board");

    // The CARD, not its move handle. `data-card` is on the handle — a button,
    // and a button is where a browser does not begin a drag. The draggable
    // element is the card itself, which is what a person picks up.
    const card = page.locator(`[data-item="${item.reference}"]`);
    const target = page.locator(`[data-column="${destination.key}"]`);

    await expect(card).toBeVisible();
    await expect(target).toBeVisible();

    // Driven by hand rather than with `dragTo`, and the reason is written into
    // the assertion below: `dragTo` hovers each element's CENTRE. A card's
    // centre is its title link, and a column's centre — in a column fifty cards
    // long — is somewhere deep in the middle of the list. One of those grabbed
    // a neighbouring card and moved it, and the board dutifully reported
    // success for a card this test had never touched.
    //
    // Explicit points, taken immediately before the move: the top strip of the
    // card, and the top of the destination column.
    const from = await card.boundingBox();
    const to = await target.boundingBox();

    if (!from || !to) throw new Error("The card or the destination column has no box.");

    await page.mouse.move(from.x + from.width / 2, from.y + 6);
    await page.mouse.down();

    // A press is not a drag. `dragstart` fires on the first MOVE with the
    // button held, so the pick-up has not happened yet — asserting here without
    // this nudge reads "the board grabbed the wrong card" when nothing has been
    // grabbed at all.
    await page.mouse.move(from.x + from.width / 2, from.y + 16, { steps: 4 });

    // Which card the board believes it picked up, asserted before the drop.
    // Getting this wrong is silent otherwise — the move succeeds, and it is the
    // wrong item.
    await expect(
      page.locator(`[data-card="${item.reference}"]`),
      'The drag started on a different card than the one under test. The point the '
        + 'press landed on is not inside this card.',
    ).toHaveAttribute("aria-pressed", "true");

    await page.mouse.move(to.x + to.width / 2, to.y + 24, { steps: 12 });
    await page.mouse.move(to.x + to.width / 2, to.y + 28, { steps: 4 });
    await page.mouse.up();

    // The board narrates itself for a screen reader, so it can narrate itself
    // to a failing test too. Read before the wait below, because "what did the
    // board think happened" is the question every failure here has turned on —
    // and reading it afterwards would only ever show the timeout.
    await page.waitForTimeout(1500);
    const said = (await page.locator("p[aria-live]").innerText()).trim();

    // ── What the database says, first ──────────────────────────────────────
    //
    // The order matters, and getting it wrong cost a run: asking the screen
    // first makes "the drop never reached the server" and "the server moved it
    // and the board did not re-render" produce the identical symptom — a card
    // still sitting in its old column.
    const moved = await eventually(
      "the drop to reach the server",
      async () => {
        const now = await call<WorkItem>(session, `/work-items/${item.reference}`);

        return now.state?.id === destination.id ? now : null;
      },
      `The card never moved. The board said: ${said === "" ? "nothing at all, which "
        + "means the drop never reached it — the gesture is not starting or the column "
        + "is not accepting it" : `"${said}"`}.`,
    );

    expect(moved.state?.id).toBe(destination.id);

    // ── and only then, what the screen says ────────────────────────────────
    //
    // That it LEFT, not that it arrived. A card lands at the end of its new
    // column's ordering and a column shows only its first fifty (ADR 0012 §6),
    // so against a busy project the move is completely correct and the card is
    // nowhere on screen.
    await expect(
      page.locator(`[data-column="${fromKey}"] [data-item="${item.reference}"]`),
      'The server moved the card and the board is still drawing it in its old '
        + 'column. The action revalidates this route — if this fails, the board is '
        + 'not re-rendering after a move, which is a stale-screen defect and not a '
        + 'timing problem.',
    ).toBeHidden();

    // ── and the trail it left ──────────────────────────────────────────────
    //
    // docs/11's other half. A drag between columns IS a status change, so it
    // must leave the same history a move made from the status menu leaves —
    // and that history has to be legible on the item's own page, not merely
    // present in a table.
    await page.goto(`/work/${item.reference}`);

    const history = page.getByRole("region", { name: "History" });

    await expect(
      history.getByText(new RegExp(`moved it to ${destination.label}`, "i")),
      'The card moved but its page does not say so. The timeline is read from '
        + '/work-items/{ref}/activity, which is behind the `activity.view` '
        + 'permission — a reader without it sees no history at all.',
    ).toBeVisible();

    await context.close();
  });

  test("the same move can be made without a mouse", async ({ browser, viewport }) => {
    const { page, session, context } = await signedInPhone(browser, AHMAD, viewport);

    const { item, destination, fromKey } = await aCardWithSomewhereToGo(session);

    await page.goto("/projects/ENG/board");

    const handle = page.locator(`[data-card="${item.reference}"]`);

    await handle.focus();
    await expect(handle).toBeFocused();

    // Space picks the card up. The board says so out loud, which is the only
    // feedback a screen-reader user gets that the gesture began.
    await page.keyboard.press("Space");
    await expect(handle).toHaveAttribute("aria-pressed", "true");

    // Walk to the destination column rather than assuming it is one step away:
    // the workflow decides the order of the columns, not this test.
    const columns = await page.locator("[data-column]").evaluateAll(
      (nodes) => nodes.map((node) => node.getAttribute("data-column") ?? ""),
    );

    const steps = columns.indexOf(destination.key) - columns.indexOf(fromKey);

    expect(steps, `Neither column is on the board: ${columns.join(", ")}`).not.toBe(0);

    for (let i = 0; i < Math.abs(steps); i++) {
      await page.keyboard.press(steps > 0 ? "ArrowRight" : "ArrowLeft");
    }

    await page.keyboard.press("Space");

    const moved = await eventually(
      "the keyboard move to land",
      async () => {
        const now = await call<WorkItem>(session, `/work-items/${item.reference}`);

        return now.state?.id === destination.id ? now : null;
      },
      'The card was picked up and dropped with the keyboard and never moved. If the '
        + 'pointer test above passed, the two paths have stopped converging on one '
        + 'call — which is exactly what ADR 0012 §4 exists to prevent.',
    );

    expect(moved.state?.id).toBe(destination.id);

    await context.close();
  });
});

/**
 * A work item of this test's own, sitting in a column with a legal move out of
 * it — and the column that move leads to.
 *
 * Created rather than borrowed: dragging a seeded card leaves the demo in a
 * state the next reader does not expect, and the run after this one starts
 * somewhere different than this one did.
 *
 * The destination is read from the workflow graph rather than named here. A
 * board whose columns come from configuration cannot be tested against a
 * hardcoded "In Progress" without the test becoming a second, worse copy of the
 * workflow.
 */
async function aCardWithSomewhereToGo(session: Session): Promise<{
  item: WorkItem;
  fromKey: string;
  destination: { id: string; key: string; label: string };
}> {
  const project = await call<{ id: string }>(session, "/projects/ENG");

  const item = await call<WorkItem>(session, "/work-items", {
    method: "POST",
    body: {
      title: `E2E board drag ${Date.now()}`,
      type: "task",
      project_id: project.id,
      priority: "medium",
    },
  });

  // To the top of its column, before anything looks for it on the board.
  //
  // A column is a window onto the first fifty cards by position (ADR 0012 §6),
  // and a brand-new item is written to the END of one. In the demo seed that is
  // still visible; against the volume fixture the card this test just created
  // is somewhere past the four hundredth and the board is right not to show it.
  // The reorder endpoint is the arrangement, so the drag has something to grab.
  if (item.state === null) {
    throw new Error(`${item.reference} was created with no state; the board cannot show it.`);
  }

  // Read from the board itself, so "the top of the column" means what the board
  // means by it rather than what a list endpoint happens to order by.
  const board = await call<{
    columns: Array<{ state: { id: string }; items: Array<{ id: string }> }>;
  }>(session, "/projects/ENG/board");

  const home = board.columns.find((c) => c.state.id === item.state?.id);
  const first = home?.items.find((card) => card.id !== item.id) ?? null;

  await call(session, `/work-items/${item.reference}/move`, {
    method: "POST",
    body: { before_id: null, after_id: first?.id ?? null },
  });

  const { transitions } = await call<{ transitions: Transition[] }>(
    session,
    `/work-items/${item.reference}/available-transitions`,
  );

  // A move that needs no reason: a drop onto a column that demands one opens
  // the comment prompt instead of moving, which is correct behaviour and a
  // different flow than this one.
  const forward = transitions.find((t) => t.available && !t.requires_comment);

  if (!forward) {
    throw new Error(
      `${item.reference} has no available move that does not require a comment, so `
        + 'there is nothing to drag it to. Has the seeded workflow changed?',
    );
  }

  return { item, fromKey: item.state.key, destination: forward.to_state };
}

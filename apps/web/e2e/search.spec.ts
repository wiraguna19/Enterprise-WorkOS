import { expect } from "@playwright/test";
import { call, type Session } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 12 — "Search from the command palette, open a result".
 *
 * The palette is the primary navigation for experienced users (docs/08 §5) and
 * the only screen that reaches search at all, so every regression in it —
 * the shortcut, the debounce, the grouping, the jump — was invisible to the
 * suite until now.
 *
 * The word searched for is made up and stamped, and planted in a COMMENT: that
 * is the one match a title search would never find, and "matched in a comment"
 * is the note that makes such a result make sense.
 */
const AHMAD = "ahmad@acme.test";

type WorkItem = { reference: string; title: string };

test.describe("search", () => {
  test("finds work by something said in a comment, and opens it", async ({ browser, viewport }) => {
    const { page, session } = await signedInPhone(browser, AHMAD, viewport);

    const item = await anEngItem(session);
    const word = `quokka${Date.now().toString(36)}`;

    await call(session, `/work-items/${item.reference}/comments`, {
      method: "POST",
      body: { body: `Noted by the end-to-end suite: ${word}.` },
    });

    await page.goto("/");

    // The shortcut, not the header button: the shortcut is what the palette
    // is for, and the button has its own tap target on a phone.
    await page.keyboard.press("ControlOrMeta+k");

    const palette = page.getByRole("dialog", { name: "Search" });

    await expect(palette).toBeVisible();
    await palette.getByRole("textbox", { name: "Search" }).fill(word);

    const hit = palette.getByRole("button", { name: new RegExp(item.reference) });

    await expect(hit, `No result for "${word}", which was just said on ${item.reference}.`).toBeVisible();
    await expect(hit).toContainText("in a comment");

    await hit.click();

    await expect(page).toHaveURL(new RegExp(`/work/${item.reference}$`));
  });

  test("jumps straight to a reference", async ({ browser, viewport }) => {
    const { page, session } = await signedInPhone(browser, AHMAD, viewport);

    const item = await anEngItem(session);

    await page.goto("/");
    await page.keyboard.press("ControlOrMeta+k");

    const palette = page.getByRole("dialog", { name: "Search" });

    // Lower case on purpose: people type references the way they say them.
    await palette.getByRole("textbox", { name: "Search" }).fill(item.reference.toLowerCase());
    await expect(palette.getByRole("button", { name: new RegExp(item.reference) })).toBeVisible();

    // Enter takes the highlighted — first — result.
    await page.keyboard.press("Enter");

    await expect(page).toHaveURL(new RegExp(`/work/${item.reference}$`));
  });
});

async function anEngItem(session: Session): Promise<WorkItem> {
  const projects = await call<Array<{ id: string; key: string }>>(session, "/projects?limit=200");
  const eng = projects.find((project) => project.key === "ENG");

  if (!eng) throw new Error("The seeded ENG project is not there.");

  const items = await call<WorkItem[]>(session, `/work-items?filter[project_id]=${eng.id}&limit=1&sort=reference`);

  if (items.length === 0) throw new Error("ENG has no work this manager can see.");

  return items[0];
}

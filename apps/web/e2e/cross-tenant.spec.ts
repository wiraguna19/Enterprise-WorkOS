import { expect, test } from "@playwright/test";
import { call, status, type Session } from "./support/api";
import { signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 14 — "Cross-tenant: a Globex user cannot reach an Acme URL
 * (expects 404)".
 *
 * The number in that line is the whole flow. A 403 would be a perfectly
 * reasonable-looking answer and a leak: it confirms the URL names something
 * real, which is exactly what a person outside the tenant must not learn
 * (docs/05 §3). So this asserts 404 specifically, and treats 403 as a failure
 * rather than as "denied, close enough".
 */
const AHMAD = "ahmad@acme.test";
const GIL = "gil@globex.test";

type WorkItem = { reference: string; title: string; project: { key: string } | null };

test.describe("cross-tenant isolation", () => {
  test("a Globex user cannot reach an Acme URL", async ({ browser, viewport }) => {
    const acme = await signedInPhone(browser, AHMAD, viewport);
    const globex = await signedInPhone(browser, GIL, viewport);

    const target = await anAcmeWorkItem(acme.session);

    // ── The control ────────────────────────────────────────────────────────
    //
    // Asserted BEFORE anything else, because without it this whole flow can
    // pass for the wrong reason: a signed-out browser is also refused every
    // Acme URL, and a broken session would make every assertion below go green
    // while proving nothing at all about tenancy.
    //
    // So first, positively: Gil is signed in and his own product works.
    const home = await globex.page.goto("/my-work");

    expect(
      home?.status(),
      'Gil could not load his own My Work page. Everything below this line would '
        + 'then pass because he is signed out, not because the tenant boundary holds '
        + '— which is the shape of a security test that proves nothing.',
    ).toBe(200);

    await expect(globex.page).not.toHaveURL(/\/login/);

    // ── The work item, through the interface ───────────────────────────────
    const item = await globex.page.goto(`/work/${target.reference}`);

    expect(
      item?.status(),
      `${target.reference} answered ${item?.status()} for a Globex user. 404 is the `
        + 'required answer: 403 tells him the reference is real, and a 200 is a '
        + 'tenant leak.',
    ).toBe(404);

    // And the page must not have leaked the title on its way to refusing.
    await expect(globex.page.getByText(target.title, { exact: false })).toHaveCount(0);

    // ── The project, and its board ─────────────────────────────────────────
    if (target.project) {
      for (const path of [`/projects/${target.project.key}/overview`, `/projects/${target.project.key}/board`]) {
        const response = await globex.page.goto(path);

        expect(response?.status(), `${path} must answer 404 for a Globex user.`).toBe(404);
      }
    }

    // ── The API underneath, which is what actually enforces this ───────────
    //
    // The pages above are refused because the API refused them. Asserting the
    // API directly as well is not redundant: the interface could start
    // answering 404 from its own routing one day and hide a backend that had
    // begun answering 200.
    expect(
      await status(globex.session, `/work-items/${target.reference}`),
      'The API is where tenancy is enforced (docs/05 §3) — the page only relays it.',
    ).toBe(404);

    if (target.project) {
      expect(await status(globex.session, `/projects/${target.project.key}`)).toBe(404);
    }

    // ── And the same URLs still work for the tenant they belong to ─────────
    //
    // Otherwise a reference that had simply been deleted, or a route that had
    // been renamed, would satisfy every assertion above.
    const stillThere = await acme.page.goto(`/work/${target.reference}`);

    expect(
      stillThere?.status(),
      `${target.reference} is refused to Ahmad too, so the 404s above prove nothing `
        + 'about tenancy — the URL is simply gone.',
    ).toBe(200);

    await expect(acme.page.getByText(target.title, { exact: false }).first()).toBeVisible();

    await acme.context.close();
    await globex.context.close();
  });
});

/** A real Acme work item, whichever one the seed happens to put first. */
async function anAcmeWorkItem(acme: Session): Promise<WorkItem> {
  const items = await call<WorkItem[]>(acme, "/work-items?limit=1");

  if (items.length === 0) {
    throw new Error(
      'Ahmad can see no work items, so there is no Acme URL to be refused. '
        + 'Has the database been seeded (`php artisan migrate:fresh --seed`)?',
    );
  }

  return items[0];
}

import { expect } from "@playwright/test";
import { call, type Session } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 2 — "Admin: create department → team → invite person →
 * assign role".
 *
 * **Two of those four steps exist, and this flow says so rather than pretending
 * otherwise.** Creating a department and creating a team were built in Phase 2
 * and had no interface until now; inviting a person and assigning a role have
 * no ENDPOINT at all. `person.invite` is granted to managers and to org admins
 * with nothing behind it — the same shape as `activity.view`, which was granted
 * to every role for five phases with no endpoint and then no reader.
 *
 * So the flow covers the half that exists, end to end through the interface,
 * and the assertion at the bottom names the half that does not. A flow quietly
 * written to two-thirds of its description is how a gap stops being visible.
 *
 * Driven as Rina: `department.create` belongs to org_admin alone. A manager can
 * create teams and not departments, which is a distinction worth having a test
 * notice if it ever quietly widens.
 */
const RINA = "rina@acme.test";

type Department = { id: string; name: string; code: string | null; parent_id: string | null };
type Team = { id: string; key: string; name: string; department: { id: string } | null };

test.describe("building the organization", () => {
  test("an admin creates a department, a team inside it, and puts someone on it", async ({
    browser,
    viewport,
  }) => {
    const admin = await signedInPhone(browser, RINA, viewport);
    const page = admin.page;
    const rina = admin.session;

    // Unique per run, because this flow ADDS to the structure and the structure
    // has no delete: a fixed code passes once and collides forever after.
    const stamp = Date.now().toString().slice(-6);
    const departmentCode = `E2E${stamp}`;
    const teamKey = `E2ET${stamp}`;

    // ── A department ───────────────────────────────────────────────────────
    await page.goto("/departments");

    await page.getByRole("link", { name: "New department" }).click();

    await page.getByLabel("Code").fill(departmentCode);
    await page.getByLabel("Name").fill(`E2E Department ${stamp}`);
    await page.getByRole("button", { name: "Create department" }).click();

    await expect(page).toHaveURL(/\/departments$/);

    const departments = await call<Department[]>(rina, "/departments");
    const created = departments.find((row) => row.code === departmentCode);

    expect(
      created,
      "The form reported no error and the department is not in the API's list, "
        + "which means the screen navigated on a request that never landed.",
    ).toBeDefined();

    // It is a root, because nothing was chosen. "A top-level department" is a
    // real answer rather than a missing one, and the API is asked to confirm
    // the product sent it as one.
    expect(created?.parent_id).toBeNull();

    // ── Renamed, in place ──────────────────────────────────────────────────
    //
    // Renaming from the list rather than from a detail page: a department is a
    // name, a code and a parent, and three facts do not need a screen each.
    const row = page.locator("li").filter({ hasText: departmentCode });

    await row.getByRole("button", { name: "Rename" }).click();
    await row.getByLabel(`Name of E2E Department ${stamp}`).fill(`E2E Renamed ${stamp}`);
    await row.getByRole("button", { name: "Save", exact: true }).click();

    // Back out of editing is the on-screen proof: the row shows "Rename" again
    // only when the action returned without an error.
    //
    // Not `page.getByText(name)` — every other row's parent picker carries an
    // <option> with this department's name in it, so the unscoped text matches
    // once per department on the page.
    await expect(row.getByRole("button", { name: "Rename" })).toBeVisible();

    // And the name actually changed, asked of the API rather than of the
    // picker that happens to echo it.
    const renamed = await call<Department[]>(rina, "/departments");

    expect(
      renamed.find((department) => department.code === departmentCode)?.name,
      "The row left editing mode without an error and the name did not change, "
        + "so the rename reported a success it did not perform.",
    ).toBe(`E2E Renamed ${stamp}`);

    // ── A team inside it ───────────────────────────────────────────────────
    await page.goto("/teams");
    await page.getByRole("link", { name: "New team" }).click();

    await page.getByLabel("Key").fill(teamKey);
    await page.getByLabel("Name").fill(`E2E Team ${stamp}`);
    await page.getByLabel("Department").selectOption({ label: `E2E Renamed ${stamp}` });
    await page.getByRole("button", { name: "Create team" }).click();

    // Straight to the team it just made, which is where the next thing a person
    // wants to do — put someone on it — actually happens.
    await expect(page).toHaveURL(/\/teams\/[0-9a-f-]{36}$/);

    const teams = await call<Team[]>(rina, "/teams");
    const team = teams.find((row) => row.key === teamKey);

    expect(team, "The team is not in the API's list.").toBeDefined();

    expect(
      team?.department?.id,
      "The team was created without the department that was chosen for it, so the "
        + "picker on that form is decoration.",
    ).toBe(created?.id);

    // ── and somebody on it ─────────────────────────────────────────────────
    const someone = await page
      .getByLabel("Add someone to this team")
      .locator("option")
      .nth(1)
      .textContent();

    expect(
      someone,
      "A team that has just been created offers nobody to add, so the rest of "
        + "this flow has nothing to do.",
    ).toBeTruthy();

    await page.getByLabel("Add someone to this team").selectOption({ index: 1 });
    await page.getByRole("button", { name: "Add", exact: true }).click();

    // Wait for the SCREEN before asking the API.
    //
    // `click()` resolves when the click has been dispatched, not when the
    // Server Action it starts has finished — so reading the API on the next
    // line raced the write and reported an empty team, which reads exactly
    // like a broken control. The person's name appearing in the list is the
    // product saying the round trip completed.
    // Scoped to the members section, because the team page lists the same
    // person twice on purpose: once as a member and once in the capacity block
    // below it. `aria-labelledby` on each `<section>` is what makes them
    // separable — an accessibility decision made for its own sake, doing this
    // work for free.
    const members = page.getByRole("region", { name: /Members/ });

    await expect(members.getByRole("listitem").filter({ hasText: someone ?? "" })).toBeVisible();

    const withMember = await call<{ members: Array<{ membership_id: string }> }>(
      rina,
      `/teams/${team?.id}`,
    );

    expect(
      withMember.members.length,
      "The member is on screen and not in the API, so the list is rendering "
        + "something the server did not store.",
    ).toBe(1);

    // ── The half that does not exist ───────────────────────────────────────
    //
    // Stated as an assertion rather than a comment, so it fails the day
    // somebody builds the endpoint without finishing this flow. `person.invite`
    // has been in the permission catalogue and granted to two roles since
    // Phase 1; nothing answers it.
    expect(
      await inviteEndpointExists(rina),
      "An invite endpoint now exists. Flow 2 is 'create department → team → "
        + "invite person → assign role' and this spec covers the first half only "
        + "— finish it rather than leaving the description longer than the test.",
    ).toBe(false);
  });
});

/**
 * Is there anything behind `person.invite` yet?
 *
 * A 404 is the answer this expects and 405 would be one too — what it must not
 * treat as "absent" is a 403, which would mean the endpoint exists and this
 * caller simply may not use it.
 */
async function inviteEndpointExists(session: Session): Promise<boolean> {
  try {
    await call(session, "/people/invite", { method: "POST", body: {} });

    return true;
  } catch (error) {
    const message = error instanceof Error ? error.message : "";

    return !message.includes("404") && !message.includes("405");
  }
}

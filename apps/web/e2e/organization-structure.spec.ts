import { expect } from "@playwright/test";
import { call } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 2 — "Admin: create department → team → invite person →
 * assign role".
 *
 * **All four steps exist now, and this flow finally covers all four.** It
 * covered two for a phase and asserted the absence of the other two at the
 * bottom — `person.invite` was granted to managers and org admins with nothing
 * behind it since Phase 1, and nothing could assign a role at all. That
 * assertion is what failed the day the endpoints landed, which is exactly what
 * it was for: a flow quietly written to two-thirds of its description is how a
 * gap stops being visible.
 *
 * The invitation half runs in TWO browser contexts, because the second half of
 * an invitation is performed by somebody the product has never met: a person
 * with no session, holding a link.
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
    // Four steps across two browser contexts, and the first project pays
    // dev-mode compilation on two routes nothing has hit yet. Marked slow with
    // the reason written down rather than the timeout quietly raised: the last
    // time a number moved here it turned a leaked browser context into "the
    // product is slow" for two rounds.
    test.slow();

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

    // ── Invite a person ────────────────────────────────────────────────────
    const address = `e2e-newcomer-${stamp}@acme.test`;

    await page.goto("/people");
    await page.getByRole("link", { name: "Invite someone" }).click();

    await page.getByLabel("Email").fill(address);
    await page.getByLabel("Role").selectOption("employee");
    await page.getByRole("button", { name: "Create the invitation" }).click();

    // The link is shown ONCE, because only its digest is stored. Reading it off
    // the screen is not a shortcut here — it is the product's only delivery
    // mechanism, and a test that fetched the token from the database would be
    // testing a path no person can take.
    const link = await page.locator("code").first().innerText();

    expect(link, "The invitation was created and no link was shown.").toContain("/invite/");

    await expect(
      page.getByText("Nothing was emailed", { exact: false }),
      "The screen must say no mail was sent. Implying an email is on its way is "
        + "the difference between a limitation and a lie.",
    ).toBeVisible();

    // ── Accept it, as somebody the product has never met ───────────────────
    //
    // A context of its own, with no session: this is the only write in the
    // product made by a person who has not signed in. Closed by hand because
    // `signedInPhone`'s auto fixture only knows about the contexts it made.
    const stranger = await browser.newContext({ viewport });

    try {
      const strangerPage = await stranger.newPage();

      await strangerPage.goto(new URL(link).pathname);

      await expect(strangerPage.getByRole("heading", { level: 1 })).toContainText("Acme");
      await expect(strangerPage.getByText(address)).toBeVisible();

      await strangerPage.getByLabel("Your name").fill(`E2E Newcomer ${stamp}`);
      await strangerPage.getByLabel("Password").fill("a-long-enough-password");
      await strangerPage.getByRole("button", { name: "Join" }).click();

      // Sent to sign in rather than signed in: accepting creates the account,
      // and logging in is the account's own act.
      await expect(strangerPage).toHaveURL(/\/login/);
    } finally {
      await stranger.close();
    }

    const joined = await call<Array<{ id: string; name: string }>>(
      rina,
      `/people?limit=100&q=${encodeURIComponent(`E2E Newcomer ${stamp}`)}`,
    );

    expect(
      joined[0],
      "They accepted and the directory does not have them, so the membership "
        + "was never written.",
    ).toBeDefined();

    // ── Assign a role — on the team this flow just built ───────────────────
    //
    // The fourth step, and scoped: authority over ONE thing is what a grant is
    // for, and it is what lets a lead manage their own team without the
    // hardcoded role check docs/06 §2 rules out by name (ADR 0016).
    await page.goto(`/people/${joined[0].id}`);

    await page.getByLabel("Give them").selectOption("manager");
    await page.getByLabel("On a").selectOption("team");
    await page.getByLabel("Which one").selectOption(team!.id);
    await page.getByRole("button", { name: "Grant" }).click();

    await expect(page.getByText(`on team E2E Team ${stamp}`)).toBeVisible();

    const grants = await call<{ scoped: Array<{ key: string; scope_id: string }> }>(
      rina,
      `/people/${joined[0].id}/roles`,
    );

    expect(
      grants.scoped.find((grant) => grant.scope_id === team!.id)?.key,
      "The grant is on screen and not in the API, so the row was never written.",
    ).toBe("manager");
  });
});

import { expect } from "@playwright/test";
import { call, type Session } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * docs/11 §4, flow 3 — "Manager: create project → add members → create
 * milestone". The first two thirds; the third does not exist.
 *
 * There is no way to make a milestone in the interface — no form, no Server
 * Action — and this spec does not pretend otherwise, the same way flow 2
 * covers the half of its own sentence that was built. When a milestone form
 * lands, this is where its steps go.
 *
 * The project is stamped with the clock: data a flow creates has no delete,
 * and a fixed key passes once and collides forever after.
 */
const AHMAD = "ahmad@acme.test";
const SARAH = "sarah@acme.test";

type Person = { id: string; name: string | null; email: string | null };
type Member = { subject: string; membership_id: string | null; role: string };

test.describe("a manager's new project", () => {
  test("is created from the directory, and somebody is given access to it", async ({
    browser,
    viewport,
  }) => {
    const { page, session } = await signedInPhone(browser, AHMAD, viewport);

    const sarah = await personByEmail(session, SARAH);
    // Uppercase letters and digits after a letter: ck_projects_key_format.
    const key = `E${Date.now().toString(36).toUpperCase().slice(-6)}`;
    const name = `E2E project ${key}`;

    // ── From the directory, the way somebody starts one ────────────────────
    await page.goto("/projects");
    await page.getByRole("link", { name: "New project", exact: true }).click();

    await page.getByLabel("Key").fill(key);
    await page.getByLabel("Name").fill(name);
    await page.getByRole("button", { name: "Create project" }).click();

    await expect(page).toHaveURL(new RegExp(`/projects/${key}/overview$`));

    // ── Access, from the project's own settings ─────────────────────────────
    // Scoped to the project's own tabs: the main nav has a "Settings" too, with
    // exactly the same name, and it leads to the organization's.
    await page
      .getByRole("navigation", { name: "Project views" })
      .getByRole("link", { name: "Settings", exact: true })
      .click();
    await expect(page).toHaveURL(new RegExp(`/projects/${key}/settings$`));

    const access = page.getByRole("region", { name: "Give access" });

    await access.getByLabel("Person").selectOption({ label: sarah.name ?? "Unnamed" });
    await access.getByLabel("Role").selectOption("member");
    await access.getByRole("button", { name: "Add", exact: true }).click();

    // The screen first, then the API (see e2e_harness: a click resolves when it
    // is dispatched, not when its Server Action has finished).
    await expect(access.getByLabel("Person").locator("option", { hasText: sarah.name ?? "" })).toHaveCount(0);

    const members = await call<Member[]>(session, `/projects/${key}/members`);

    expect(
      members.find((member) => member.membership_id === sarah.id)?.role,
      `Sarah was added on screen and is not a member of ${key} in the API.`,
    ).toBe("member");

    // The creator owns it — the project is theirs from the moment it exists.
    expect(members.some((member) => member.role === "owner")).toBe(true);
  });
});

async function personByEmail(session: Session, email: string): Promise<Person> {
  const people = await call<Person[]>(session, "/people?limit=200");
  const person = people.find((candidate) => candidate.email === email);

  if (!person) throw new Error(`No seeded person with the email ${email}.`);

  return person;
}

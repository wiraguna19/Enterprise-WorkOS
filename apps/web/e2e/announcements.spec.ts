import { expect } from "@playwright/test";
import { call } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * Announcements (ADR 0061): a manager tells one team something that needs
 * confirming, a member of that team confirms it, and the manager sees who has
 * not.
 *
 * Ahmad heads Engineering, so Frontend is one of the groups he may address.
 * Its members are Sarah and Budi. The title is stamped so a run never finds
 * an earlier run's announcement, and the announcement is removed through the
 * screen at the end — which is the last thing the flow proves.
 */
const AHMAD = "ahmad@acme.test";
const SARAH = "sarah@acme.test";

type Announcement = { id: string; title: string };

test.describe("announcements", () => {
  test("is published to a team, acknowledged by a member, and the publisher sees who has not", async ({
    browser,
    viewport,
  }) => {
    const manager = await signedInPhone(browser, AHMAD, viewport);
    const title = `Release freeze ${Date.now().toString(36)}`;

    await manager.page.goto("/announcements/new");
    await expect(manager.page.getByRole("heading", { name: "New announcement", level: 1 })).toBeVisible();

    // The groups on offer are the ones he may address: not everyone, not Marketing.
    const to = manager.page.getByLabel("To", { exact: true });
    await expect(to.locator("option", { hasText: "Frontend team" })).toHaveCount(1);
    await expect(to.locator("option", { hasText: "everyone" })).toHaveCount(0);
    await expect(to.locator("option", { hasText: "Marketing" })).toHaveCount(0);

    await to.selectOption({ label: "Frontend team" });
    await manager.page.getByLabel("Title", { exact: true }).fill(title);
    await manager.page.getByLabel("Message").fill("No merges to main from Thursday.\nAsk Ahmad for exceptions.");
    await manager.page.getByLabel("Ask everyone to confirm they read it").check();
    await manager.page.getByRole("button", { name: "Publish" }).click();

    await expect(manager.page).toHaveURL(/\/announcements\/[0-9a-f-]{36}$/);
    const id = manager.page.url().split("/").pop() ?? "";

    const reach = manager.page.getByRole("region", { name: "Who has seen it" });
    await expect(reach).toContainText("0 of 2");
    await expect(reach.getByRole("list", { name: "Not acknowledged yet" })).toContainText("Sarah Chen");

    try {
      // ── A member of the team ───────────────────────────────────────────
      const member = await signedInPhone(browser, SARAH, viewport);

      // Home says there is something new, and leads to it.
      await member.page.goto("/");
      const glance = member.page.getByRole("navigation", { name: "At a glance" });
      await glance.getByRole("link", { name: /New announcements/ }).click();
      await expect(member.page).toHaveURL(/\/announcements$/);

      const card = member.page.getByRole("article", { name: title });
      await expect(card).toContainText("New");
      await expect(card).toContainText("No merges to main from Thursday.");

      await card.getByRole("button", { name: "I have read this" }).click();
      await expect(card).toContainText("You confirmed you read this");

      // Reading and confirming are two things, and the server holds both.
      const feed = await call<Array<Announcement & { read: boolean; acknowledged: boolean }>>(
        member.session,
        "/announcements",
      );
      const mine = feed.find((announcement) => announcement.id === id);

      expect(mine?.read).toBe(true);
      expect(mine?.acknowledged).toBe(true);

      // ── Back to the publisher ──────────────────────────────────────────
      await manager.page.reload();

      await expect(reach).toContainText("1 of 2");
      const waiting = reach.getByRole("list", { name: "Not acknowledged yet" });
      await expect(waiting).toContainText("Budi Santoso");
      await expect(waiting).not.toContainText("Sarah Chen");
    } finally {
      await manager.page.goto(`/announcements/${id}`);
      await manager.page.getByRole("button", { name: "Remove", exact: true }).click();
      await manager.page.getByRole("button", { name: "Yes, remove" }).click();
      await expect(manager.page).toHaveURL(/\/announcements$/);
      await expect(manager.page.getByText(title)).toHaveCount(0);
    }
  });
});

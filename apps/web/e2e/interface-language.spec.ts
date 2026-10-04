import { expect } from "@playwright/test";
import { call } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * A second interface language (ADR 0060).
 *
 * Not one of docs/11 §4's fifteen flows. It is here because the language is
 * saved on the server and read back on the next render, so the only proof that
 * the round trip works is a page that changes language after the choice.
 *
 * Budi, because no other flow signs in as him: every other seeded account is
 * pinned to English for specs that find controls by their English names, and
 * the `finally` puts him back even when an assertion fails halfway.
 */
const BUDI = "budi@acme.test";

test.describe("interface language", () => {
  test("choosing Indonesian translates the shell and the screens moved over so far", async ({
    browser,
    viewport,
  }) => {
    const { page, session } = await signedInPhone(browser, BUDI, viewport);

    try {
      // Arranged, not assumed. The first run found Budi already in Indonesian —
      // somebody had tried the screen by hand — and the spec failed on the
      // English heading it expected before it had changed anything.
      await call(session, "/auth/me", { method: "PATCH", body: { locale: "en" } });

      await page.goto("/settings/language");
      await expect(page.getByRole("heading", { name: "Language", level: 1 })).toBeVisible();

      await page.getByRole("radio", { name: "Bahasa Indonesia" }).check();

      // Said in the language just chosen, and the page around it follows.
      await expect(page.getByRole("status")).toContainText("Tersimpan");
      await expect(page.getByRole("heading", { name: "Bahasa", level: 1 })).toBeVisible();

      const me = await call<{ user: { locale: string } }>(session, "/auth/me");
      expect(me.user.locale).toBe("id");

      await page.goto("/settings");
      await expect(page.getByRole("heading", { name: "Pengaturan", level: 1 })).toBeVisible();
      await expect(page.getByRole("link", { name: "Bahasa", exact: true })).toBeVisible();
      await expect(page.locator("html")).toHaveAttribute("lang", "id");

      // Home and My Work, the next two screens moved over.
      await page.goto("/");
      await expect(page.getByRole("heading", { level: 1 })).toContainText("Selamat");

      await page.goto("/my-work");
      await expect(page.getByRole("heading", { name: "Pekerjaan Saya", level: 1 })).toBeVisible();
      await expect(page.getByRole("navigation", { name: "Tampilan pekerjaan" })).toBeVisible();
      await expect(page.getByRole("link", { name: "Hari ini", exact: true })).toBeVisible();

      // Inbox, with the approval screen it leads to.
      await page.goto("/inbox");
      await expect(page.getByRole("heading", { name: "Kotak Masuk", level: 1 })).toBeVisible();
      await expect(page.getByRole("navigation", { name: "Bagian kotak masuk" })).toBeVisible();
      await expect(page.getByRole("link", { name: /Perlu keputusan Anda/ })).toBeVisible();

      // A work item: any one Budi can see.
      const [item] = await call<Array<{ reference: string }>>(session, "/work-items?limit=1");
      if (item !== undefined) {
        await page.goto(`/work/${item.reference}`);
        await expect(page.getByRole("heading", { name: "Komentar" })).toBeVisible();
        await expect(page.getByRole("heading", { name: "Riwayat", exact: true })).toBeVisible();
      }

      // Projects: the list, an overview and its board.
      const [project] = await call<Array<{ key: string }>>(session, "/projects");
      if (project !== undefined) {
        await page.goto("/projects");
        await expect(page.getByRole("heading", { name: "Proyek", level: 1 })).toBeVisible();

        await page.goto(`/projects/${project.key}/overview`);
        await expect(page.getByRole("navigation", { name: "Tampilan proyek" })).toBeVisible();
        await expect(page.getByRole("heading", { name: "Kesehatan" })).toBeVisible();

        await page.goto(`/projects/${project.key}/board`);
        await expect(page.getByRole("link", { name: "Ringkasan", exact: true })).toBeVisible();
      }

      // The new-project screen answers in Indonesian whether or not Budi may
      // create one: the refusal is translated too.
      await page.goto("/projects/new");
      await expect(page.getByRole("heading", { name: "Proyek baru", level: 1 })).toBeVisible();

      // Work: the browse screen and the create form (or its refusal).
      await page.goto("/work");
      await expect(page.getByRole("heading", { name: "Pekerjaan", level: 1 })).toBeVisible();
      await expect(page.getByRole("heading", { name: "Filter" })).toBeVisible();

      await page.goto("/work/new");
      await expect(page.getByRole("heading", { name: "Item kerja baru", level: 1 })).toBeVisible();
    } finally {
      await call(session, "/auth/me", { method: "PATCH", body: { locale: "en" } }).catch(
        () => undefined,
      );
    }
  });
});

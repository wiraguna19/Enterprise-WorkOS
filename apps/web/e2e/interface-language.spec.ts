import { expect } from "@playwright/test";
import { call, signIn } from "./support/api";
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

      // Calendar, Timesheet and Recurring.
      await page.goto("/calendar");
      await expect(page.getByRole("heading", { name: "Kalender", level: 1 })).toBeVisible();

      await page.goto("/time");
      await expect(page.getByRole("heading", { name: "Lembar Waktu", level: 1 })).toBeVisible();

      // Recurring is behind work_item.create; Budi's role decides whether he
      // gets the screen or a 404.
      const { permissions } = await call<{ permissions: string[] }>(session, "/auth/me");
      if (permissions.includes("work_item.create")) {
        await page.goto("/recurring");
        await expect(page.getByRole("heading", { name: "Pekerjaan berulang", level: 1 })).toBeVisible();
      }

      // People, Teams and Departments. Each page 404s without its read
      // permission, so each is visited only when Budi's role carries it.
      if (permissions.includes("person.view")) {
        await page.goto("/people");
        await expect(page.getByRole("heading", { name: "Orang", level: 1 })).toBeVisible();
      }

      if (permissions.includes("team.view")) {
        await page.goto("/teams");
        await expect(page.getByRole("heading", { name: "Tim", level: 1 })).toBeVisible();
      }

      if (permissions.includes("department.view")) {
        await page.goto("/departments");
        await expect(page.getByRole("heading", { name: "Departemen", level: 1 })).toBeVisible();
      }

      // Settings that belong to the person rather than the organization:
      // every account has these four, so none is guarded.
      await page.goto("/settings/notifications");
      await expect(page.getByRole("heading", { name: "Notifikasi", level: 1 })).toBeVisible();
      await expect(page.getByRole("heading", { name: "Keputusan" })).toBeVisible();

      await page.goto("/settings/two-factor");
      await expect(
        page.getByRole("heading", { name: "Autentikasi dua faktor", level: 1 }),
      ).toBeVisible();

      await page.goto("/settings/sessions");
      await expect(page.getByRole("heading", { name: "Perangkat yang masuk", level: 1 })).toBeVisible();
      await expect(page.getByText("perangkat ini")).toBeVisible();

      await page.goto("/settings/api-tokens");
      await expect(page.getByRole("heading", { name: "Token API", level: 1 })).toBeVisible();
      await expect(page.getByRole("heading", { name: "Token Anda" })).toBeVisible();

      // The organization's settings, each behind its own permission: Budi's
      // role decides which of these he reaches, and the rest 404.
      if (permissions.includes("organization.view")) {
        await page.goto("/settings/organization");
        await expect(page.getByRole("heading", { name: "Organisasi", level: 1 })).toBeVisible();
      }

      if (permissions.includes("audit_log.view")) {
        await page.goto("/settings/audit");
        await expect(page.getByRole("heading", { name: "Log audit", level: 1 })).toBeVisible();
      }

      if (permissions.includes("service_account.manage")) {
        await page.goto("/settings/service-accounts");
        await expect(page.getByRole("heading", { name: "Service account", level: 1 })).toBeVisible();
      }

      if (permissions.includes("sso.manage")) {
        await page.goto("/settings/sso");
        await expect(page.getByRole("heading", { name: "Single sign-on", level: 1 })).toBeVisible();
      }

      if (permissions.includes("webhook.manage")) {
        await page.goto("/settings/webhooks");
        await expect(page.getByRole("heading", { name: "Webhook", level: 1 })).toBeVisible();
      }

      // How work is configured. Workflows and rules are readable by anyone
      // signed in; roles and fields are behind their own permissions.
      await page.goto("/settings/workflows");
      await expect(page.getByRole("heading", { name: "Workflow", level: 1 })).toBeVisible();

      await page.goto("/settings/rules");
      await expect(page.getByRole("heading", { name: "Aturan otomatisasi", level: 1 })).toBeVisible();
      await expect(page.getByText("Saat status pekerjaan berubah").first()).toBeVisible();

      if (permissions.includes("role.view")) {
        await page.goto("/settings/roles");
        await expect(page.getByRole("heading", { name: "Role", level: 1 })).toBeVisible();
      }

      if (permissions.includes("custom_field.manage")) {
        await page.goto("/settings/fields");
        await expect(page.getByRole("heading", { name: "Field kustom", level: 1 })).toBeVisible();
      }

      // Reports: the Flow page, behind report.view.
      if (permissions.includes("report.view")) {
        await page.goto("/reports");
        await expect(page.getByRole("heading", { name: "Alur", level: 1 })).toBeVisible();
      }
    } finally {
      await call(session, "/auth/me", { method: "PATCH", body: { locale: "en" } }).catch(
        () => undefined,
      );
    }
  });

  test("the signed-out pages answer in the language the browser chose", async ({
    browser,
    viewport,
    baseURL,
  }) => {
    // Nobody is signed in here, so the language comes from the cookie the
    // sign-in page's switch writes — set directly, as the switch would.
    const context = await browser.newContext({ viewport: viewport ?? undefined });
    await context.addCookies([{ name: "wos_locale", value: "id", url: baseURL ?? "http://localhost:3000" }]);
    const page = await context.newPage();

    try {
      await page.goto("/login/sso");
      await expect(page.getByText("Masuk lewat penyedia identitas organisasi Anda.")).toBeVisible();
      await expect(page.getByLabel("Email kantor")).toBeVisible();

      // An invitation, accepted in Indonesian. The account it creates must
      // open in Indonesian too — before this it was always created in English.
      // Rina's saved session, not a fresh sign-in: sign-in is limited to five
      // a quarter hour per person, and a sign-in per run per project spent hers
      // within an afternoon. The newcomer below signs in for real — that IS
      // the claim — and is a new person every run.
      const rina = (await signedInPhone(browser, "rina@acme.test", viewport)).session;
      const address = `e2e-bahasa-${Date.now()}@acme.test`;
      const { token } = await call<{ token: string }>(rina, "/people/invite", {
        method: "POST",
        body: { email: address, role: "employee" },
      });

      await page.goto(`/invite/${token}`);
      await expect(
        page.getByRole("heading", { name: /^Bergabung dengan /, level: 1 }),
      ).toBeVisible();

      await page.getByLabel("Nama Anda").fill("Pendatang Baru");
      await page.getByLabel("Kata sandi").fill("kata-sandi-cukup-panjang");
      await page.getByRole("button", { name: "Bergabung", exact: true }).click();
      await expect(page).toHaveURL(/\/login/);

      const newcomer = await signIn(address, "kata-sandi-cukup-panjang");
      const me = await call<{ user: { locale: string } }>(newcomer, "/auth/me");
      expect(me.user.locale).toBe("id");
    } finally {
      await context.close();
    }
  });
});

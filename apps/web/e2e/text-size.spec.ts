import { expect } from "@playwright/test";
import { call } from "./support/api";
import { test, signedInPhone } from "./support/auth";

/**
 * Choosing a larger reading size (Settings → Display).
 *
 * Not one of docs/11 §4's fifteen flows. Here because the size is saved on the
 * account and put on `<html>` from a cookie before the first paint, and only a
 * reload proves both halves agree. Then the claim that matters to the person
 * who needs it: at the largest size, on a phone, the page still fits — every
 * token is in rem, so a stray px would show up as a page that scrolls sideways.
 *
 * Maya, because no other flow signs in as her; the harness puts her back at
 * the normal size whenever any spec opens her phone, and the `finally` here
 * does it even when an assertion fails halfway.
 */
const MAYA = "maya@acme.test";

test.describe("text size", () => {
  test("a larger size is chosen, survives a reload, and the page still fits", async ({
    browser,
    viewport,
  }) => {
    const { page, session } = await signedInPhone(browser, MAYA, viewport);

    try {
      await page.goto("/settings/display");
      await expect(page.getByRole("heading", { name: "Display", level: 1 })).toBeVisible();

      const root = page.locator("html");
      const rootSize = () =>
        page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));

      const normal = await rootSize();

      await page.getByRole("radio", { name: /Larger/ }).check();
      await expect(page.getByRole("status")).toContainText("Saved");
      await expect(root).toHaveAttribute("data-text-size", "larger");

      // A quarter larger than the normal size, whatever the browser's default.
      expect(await rootSize()).toBeCloseTo(normal * 1.25, 1);

      const me = await call<{ user: { text_size: string } }>(session, "/auth/me");
      expect(me.user.text_size).toBe("larger");

      // From the cookie, at first paint — not set again by script after it.
      await page.reload();
      await expect(root).toHaveAttribute("data-text-size", "larger");

      for (const path of ["/", "/my-work", "/inbox"]) {
        await page.goto(path);
        await expect(page.getByRole("heading", { level: 1 })).toBeVisible();

        const overflow = await page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );

        expect(overflow, `${path} scrolls sideways at the largest text size`).toBeLessThanOrEqual(0);
      }
    } finally {
      await call(session, "/auth/me", { method: "PATCH", body: { text_size: "normal" } }).catch(
        () => undefined,
      );
    }
  });
});

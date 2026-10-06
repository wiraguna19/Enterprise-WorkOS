import { expect, test } from "@playwright/test";

/**
 * Every page carries the headers in `next.config.ts`.
 *
 * Asked of the sign-in page because it is reachable signed out and is the one
 * a framing attack would most like to sit on top of. No session, so this spec
 * spends nobody's sign-in attempts.
 */
test("pages refuse to be framed and say what they are", async ({ request }) => {
  const response = await request.get("/login");

  expect(response.ok()).toBe(true);

  const headers = response.headers();

  expect(headers["content-security-policy"]).toContain("frame-ancestors 'none'");
  expect(headers["x-frame-options"]).toBe("DENY");
  expect(headers["x-content-type-options"]).toBe("nosniff");
  expect(headers["referrer-policy"]).toBe("strict-origin-when-cross-origin");
  expect(headers["permissions-policy"]).toContain("camera=()");
  expect(headers["x-powered-by"]).toBeUndefined();
});

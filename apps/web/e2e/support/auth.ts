import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { expect, type Browser, type BrowserContext, type Page } from "@playwright/test";
import { SESSION_COOKIE } from "../../src/lib/session-cookie";
import type { Session } from "./api";

/**
 * A signed-in phone, reusing yesterday's session where there is one.
 *
 * Signing in is rate-limited to five attempts per quarter hour per person
 * (`docs/06` §1), which is correct for the product and hostile to a suite that
 * signs two people in on every run: three runs in ten minutes and the next one
 * fails at the login form with "Too many requests", which reads exactly like a
 * broken sign-in. It cost two debugging rounds here before the screenshot said
 * so out loud.
 *
 * So the session is saved and reused. The first run of the day signs in through
 * the form like a person; the rest of the day costs nothing. Login itself is
 * not what these flows are testing — `docs/11` §4 gives it a flow of its own,
 * which is where it belongs.
 */
// Relative to the working directory, not to this file: Playwright transpiles
// these to CommonJS, where `import.meta` is a syntax error and `__dirname`
// depends on how the runner was invoked. It runs from the config's directory,
// which is `apps/web`.
const STATE_DIR = join(process.cwd(), "e2e", ".auth");

export type Phone = {
  context: BrowserContext;
  page: Page;
  session: Session;
};

export async function signedInPhone(
  browser: Browser,
  email: string,
  viewport: { width: number; height: number } | null,
): Promise<Phone> {
  const statePath = join(STATE_DIR, `${email.replace(/[^a-z0-9]/gi, "-")}.json`);
  const stored = usableState(statePath);

  const context = await browser.newContext({
    viewport: viewport ?? undefined,
    storageState: stored ?? undefined,
  });

  const page = await context.newPage();

  if (!stored) {
    await signInThroughTheForm(page, email);
    mkdirSync(STATE_DIR, { recursive: true });
    await context.storageState({ path: statePath });
  }

  return { context, page, session: await sessionFrom(page) };
}

/**
 * Sign in the way a person does, not by injecting a cookie.
 *
 * The session cookie is HttpOnly and set by a Server Action; forging one here
 * would skip the only part of authentication this suite can observe.
 */
async function signInThroughTheForm(page: Page, email: string): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password").fill("password");
  await page.getByRole("button", { name: /sign in/i }).click();

  // The failure this catches is almost always the rate limit rather than the
  // password, so the message says which to check first.
  await expect(
    page,
    `Sign-in for ${email} did not leave /login. If the page says "Too many requests", `
      + 'that is docs/06 §1 doing its job — wait fifteen minutes, or delete e2e/.auth '
      + 'only when you actually need a fresh session.',
  ).not.toHaveURL(/\/login/);
}

/**
 * The API session, taken from the cookie the sign-in set.
 *
 * The cookie is HttpOnly — unreachable from page JavaScript, which is the point
 * (docs/06 §1) — but Playwright reads it from the context, which is not the
 * browser's heap. What it holds is the bearer token this app forwards to the
 * API, so a flow gets its API session without a second sign-in.
 */
async function sessionFrom(page: Page): Promise<Session> {
  const cookie = (await page.context().cookies()).find((c) => c.name === SESSION_COOKIE);

  if (!cookie) {
    throw new Error(
      `No ${SESSION_COOKIE} cookie after signing in. Either the sign-in failed silently, `
        + 'or the cookie name changed — see apps/web/src/lib/session-cookie.ts.',
    );
  }

  // Decoded, not raw. A Sanctum token is `<id>|<secret>`, and a cookie value
  // carrying a pipe comes back percent-encoded — sending that as a bearer gets
  // a 401 that looks like a broken session rather than a mangled string.
  return { token: decodeURIComponent(cookie.value) };
}

/** A saved session, if there is one and it has not expired. */
function usableState(path: string): string | null {
  if (!existsSync(path)) {
    return null;
  }

  try {
    const state = JSON.parse(readFileSync(path, "utf8")) as {
      cookies?: Array<{ name: string; expires: number }>;
    };

    const cookie = state.cookies?.find((c) => c.name === SESSION_COOKIE);

    // A minute of margin: a session that expires mid-flow is worse than one
    // this run signs in for.
    if (cookie && (cookie.expires === -1 || cookie.expires * 1000 > Date.now() + 60_000)) {
      return path;
    }
  } catch {
    // A corrupt state file is not worth a failing suite; sign in again.
  }

  writeFileSync(path, JSON.stringify({ cookies: [], origins: [] }));

  return null;
}

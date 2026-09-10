import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { expect, test as base, type Browser, type BrowserContext, type Page } from "@playwright/test";
import { SESSION_COOKIE } from "../../src/lib/session-cookie";
import { call, type Session } from "./api";

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

/**
 * Every context this test opened, closed for it when the test ends.
 *
 * A context created with `browser.newContext()` belongs to the test that made
 * it, and Playwright closes only the BROWSER, at the end of the worker. Five of
 * the ten specs never closed theirs, so their pages stayed open for the whole
 * run — each holding an HMR websocket the dev server keeps broadcasting to, and
 * a React tree the browser keeps alive.
 *
 * The cost was not a leak warning; it was arithmetic. review-loop ran 10.7s on
 * its own and over two minutes in the full suite, and the trace showed the time
 * going into INTERACTIONS rather than loads — 38.7s to click one button that
 * never became stable. It looked exactly like a slow product, and for two runs
 * I treated it as one and raised the timeout.
 *
 * So it is a fixture rather than a rule. `auto: true` means a spec cannot
 * forget it by importing `test` from the wrong place — importing it from HERE
 * is the only thing a spec has to get right, and a spec that imports the plain
 * `test` gets no `signedInPhone` either.
 */
const opened: BrowserContext[] = [];

export const test = base.extend<{ closeOpenedContexts: void }>({
  closeOpenedContexts: [
    async ({}, use) => {
      await use();

      // Splice, so a context closed by the spec itself is not closed twice and
      // a failure in one close does not strand the others.
      await Promise.all(
        opened.splice(0).map((context) => context.close().catch(() => undefined)),
      );
    },
    { auto: true },
  ],
});

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

  opened.push(context);

  const page = await context.newPage();

  if (!stored) {
    await signInThroughTheForm(page, email);
    mkdirSync(STATE_DIR, { recursive: true });
    await context.storageState({ path: statePath });

    return { context, page, session: await sessionFrom(page) };
  }

  // A cookie that has not expired is not the same as a session the server still
  // knows about, and the gap between the two is one `migrate:fresh` wide. The
  // saved state then sails through every local check and every flow fails in
  // its arrange step with a 401 from whichever endpoint it happened to call
  // first — five different stack traces for one dropped table.
  //
  // So the reused session is asked one cheap question before it is trusted.
  const session = await sessionFrom(page);

  if (await stillValid(session)) {
    return { context, page, session };
  }

  rmSync(statePath, { force: true });

  await signInThroughTheForm(page, email);
  await context.storageState({ path: statePath });

  return { context, page, session: await sessionFrom(page) };
}

/** Does the API still recognise this token? */
async function stillValid(session: Session): Promise<boolean> {
  try {
    await call(session, "/auth/me");

    return true;
  } catch {
    // Any failure is treated as "sign in again", including the API being down.
    // The sign-in that follows fails with a message that names the API, which
    // is a better answer than this function guessing at the difference.
    return false;
  }
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

/**
 * A direct line to the API, for the parts of a flow that are not the point.
 *
 * docs/11 §4: "Each flow asserts the resulting DATA, not just the visible text
 * — the activity log, the assignment rows, and the notification records are
 * checked, because a UI that looks right over a wrong database is the failure
 * mode that matters."
 *
 * So this is used for two things and never for a third: arranging the starting
 * position, and reading back what really happened. The steps under test are
 * always performed through the interface, because a test that drives the API
 * and then checks the API proves the API twice and the product not at all.
 */
const BASE = process.env.E2E_API_URL ?? "http://127.0.0.1:8000/api/v1";

export type Session = { token: string };

export async function signIn(email: string, password = "password"): Promise<Session> {
  const response = await fetch(`${BASE}/auth/login`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ email, password }),
  });

  if (!response.ok) {
    throw new Error(
      `Could not sign in as ${email} (${response.status}). Is the API running on ${BASE}?`,
    );
  }

  const body = await response.json();

  return { token: body.data.token };
}

/**
 * Every collection in this API is cursor-paginated, and the flows ask for the
 * maximum page (`limit=100`) where they scan one.
 *
 * That is a known ceiling, not a fix. The review queue is oldest-first, so the
 * approval a flow has just created sits at the END of it, and a database
 * carrying more than a hundred pending approvals pages it out — which is what a
 * stale test database looks like from in here. Reseed; the number cannot go
 * higher (`CursorPage::MAX_PER_PAGE`).
 */
export async function call<T>(
  session: Session,
  path: string,
  init: { method?: string; body?: unknown } = {},
): Promise<T> {
  const response = await fetch(`${BASE}${path}`, {
    method: init.method ?? "GET",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${session.token}`,
    },
    body: init.body === undefined ? undefined : JSON.stringify(init.body),
  });

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error(
      `${init.method ?? "GET"} ${path} → ${response.status}: ${payload?.error?.message ?? "no body"}`,
    );
  }

  return payload?.data as T;
}

/**
 * The status code, when the status code IS the assertion.
 *
 * `call` throws on anything but 2xx, which is right when a flow needs the data
 * and wrong when it needs to prove that a URL answers 404 rather than 403.
 * That distinction is the entire content of flow 14: a 403 confirms the thing
 * exists, and confirming existence across a tenant boundary is the leak
 * (docs/05 §3).
 */
export async function status(session: Session, path: string): Promise<number> {
  const response = await fetch(`${BASE}${path}`, {
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${session.token}`,
    },
  });

  return response.status;
}

/**
 * Wait for something that has not happened yet, and say what to check when it
 * never does.
 *
 * The hint is a parameter because it is the whole value of this helper. The
 * first version hardcoded "is the queue worker running?", which was right for
 * the one call site it was written for and actively misleading for the next —
 * a synchronous decision that failed to record was reported as a dormant queue,
 * and I went looking in the wrong place.
 */
export const QUEUE_HINT =
  'This work is done by a queued job — is `php artisan queue:work` running? '
  + 'Without it the rule engine is dormant and approvals are never created.';

export async function eventually<T>(
  what: string,
  attempt: () => Promise<T | null>,
  /**
   * A sentence, or a function that goes and looks.
   *
   * The callable form exists because a timeout says only that something did not
   * appear, which is the same message whether nothing was written or the test
   * was reading the wrong list. It runs once, at the deadline, and what it
   * returns goes in the failure — so the next question is answered before
   * anybody has to ask it. Two rounds of this suite were spent proving a
   * notification existed in the database while the test that could not see it
   * said nothing about what it COULD see.
   */
  hint: string | (() => Promise<string> | string),
  timeoutMs = 15_000,
): Promise<T> {
  const deadline = Date.now() + timeoutMs;

  for (;;) {
    const result = await attempt();

    if (result !== null) {
      return result;
    }

    if (Date.now() > deadline) {
      const explanation = typeof hint === "string"
        ? hint
        : await Promise.resolve(hint()).catch(
          (error: unknown) => `(the hint itself failed: ${String(error)})`,
        );

      throw new Error(`Timed out waiting for ${what}. ${explanation}`);
    }

    await new Promise((resolve) => setTimeout(resolve, 500));
  }
}

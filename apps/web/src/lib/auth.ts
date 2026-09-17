import { redirect } from "next/navigation";
import { api, ApiRequestError } from "./api";

/**
 * The bootstrap read every authenticated page performs.
 *
 * Returns identity, tenant, and the permission set — the last of which is what
 * lets the UI render controls without inventing its own copy of the rules
 * (docs/07 §4). The server remains the only authority; this describes its
 * decision.
 */

export type CurrentUser = {
  user: {
    id: string;
    name: string;
    email: string;
    timezone: string;
    /** Whether a second factor is on (ADR 0030). The API has reported this
     *  since Phase 1 and nothing read it, because nothing could turn it on. */
    mfa_enabled: boolean;
  };
  membership: { id: string; status: string; job_title: string | null };
  organization: {
    id: string;
    name: string;
    slug: string;
    /** This organization requires a second factor of everybody (ADR 0033). */
    requires_second_factor: boolean;
  };
  permissions: string[];
};

/**
 * The bootstrap read, and the one place the two-factor confinement is decided.
 *
 * Every page in the app awaits this before it renders anything, which is what
 * makes it the right place — and it took three attempts to get here (ADR 0033):
 *
 * 1. In the app layout, which cannot tell which page is rendering inside it. It
 *    needed the path forwarded as a header, and when that header did not arrive
 *    it redirected the enrolment screen to itself, forever.
 * 2. In `api()`, on the 403 itself. That works for a page that lets the error
 *    through and fails silently for one that does not: the home screen catches
 *    its own data errors and renders "No work assigned to you yet", so a
 *    confined person was shown a confident lie about their own work. A
 *    `redirect()` is thrown, and a `.catch()` written for a missing list
 *    swallows it exactly as well as it swallows a 403.
 * 3. Here. No header, no race, no catch to fall into — and no loop, because the
 *    one screen that must not redirect says so itself.
 */
export async function requireUser(
  options: {
    /**
     * For the enrolment screen and the shell around it, which must render FOR a
     * confined person rather than send them away.
     */
    allowUnenrolled?: boolean;
  } = {},
): Promise<CurrentUser> {
  let me: CurrentUser;

  try {
    // NOT cached, and this is the whole point of the call. It was fetched with
    // `tags: ["session"], revalidate: false` — cached forever behind a tag
    // nothing in the product ever invalidated — so after a session was ended
    // from another device this app went on rendering as though the person were
    // still signed in, until some OTHER request happened to get the 401 first.
    // That is exactly what happened the first time a session was ended for
    // real: the layout's `/teams` call took the 401 and the browser got a 500
    // instead of the sign-in screen.
    //
    // One small request per render is the correct price for "am I still signed
    // in", and it is the only call in the product whose answer must never be a
    // moment old.
    const { data } = await api<CurrentUser>("/auth/me", { revalidate: 0 });

    me = data;
  } catch (error) {
    if (isSignedOut(error)) redirect("/login");

    throw error;
  }

  // OUTSIDE the try, deliberately. `redirect()` works by throwing, and a
  // redirect thrown inside a block that catches "the session is gone" is one
  // rewrite away from being swallowed by it.
  if (
    !options.allowUnenrolled
    && me.organization.requires_second_factor
    && !me.user.mfa_enabled
  ) {
    redirect("/settings/two-factor");
  }

  return me;
}

/**
 * The session is gone, or it can no longer act here.
 *
 * Exported because `requireUser()` cannot be the only place that handles it:
 * a page may make several calls, and the one that discovers the revocation
 * might not be this one.
 */
export function isSignedOut(error: unknown): boolean {
  return error instanceof ApiRequestError && (error.status === 401 || error.status === 403);
}

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

export async function requireUser(): Promise<CurrentUser> {
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

    return data;
  } catch (error) {
    if (isSignedOut(error)) redirect("/login");

    throw error;
  }
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

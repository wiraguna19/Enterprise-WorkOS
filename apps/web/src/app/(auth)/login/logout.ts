"use server";

import { redirect } from "next/navigation";
import { api } from "@/lib/api";
import { clearSessionToken } from "@/lib/session";

/**
 * Sign out (docs/06 §1).
 *
 * Two steps, and the order matters: the API is told first so the session row is
 * revoked server-side, then the cookie is dropped. Doing only the second would
 * leave a live token that a copy of the cookie could still use — "logged out"
 * has to mean the credential stopped working, not that this browser forgot it.
 *
 * The API call's failure is swallowed on purpose. If the server cannot be
 * reached, the honest outcome is still to let the person leave: refusing to sign
 * out because the network is down strands them in an account they are trying to
 * exit, and the session expires on its own regardless.
 */
export async function logout(): Promise<void> {
  try {
    await api("/auth/logout", { method: "POST" });
  } catch {
    // Deliberately ignored — see above.
  }

  await clearSessionToken();

  redirect("/login");
}

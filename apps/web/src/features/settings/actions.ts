"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";
import type { Preference } from "./types";

/**
 * Save one notification preference.
 *
 * The whole screen was a dead control: three toggles per type, rendered from
 * the preferences it read back — the most convincing kind, because a control
 * that shows your saved state looks like a control that saved it. `PUT
 * /notifications/preferences` had never been called from anywhere.
 *
 * **The whole triple is sent every time, not the field that changed.** The API
 * writes with `updateOrInsert` and fills what it is not given with the
 * defaults, so a PUT carrying only `email` would silently reset in-app and the
 * digest. That is the API's contract and this is the client that must respect
 * it — but it is exactly the sort of thing worth a note in the caller, since
 * "only send what changed" is the instinct.
 */
export async function saveNotificationPreference(
  preference: Preference,
): Promise<{ error: string | null }> {
  try {
    await api("/notifications/preferences", {
      method: "PUT",
      body: {
        type: preference.type,
        in_app: preference.in_app,
        email: preference.email,
        digest: preference.digest,
      },
    });
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return { error: error.error.message };
    }

    return { error: "We could not reach the server. Please try again." };
  }

  revalidatePath("/settings/notifications");

  return { error: null };
}

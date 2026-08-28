"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";

export type TimeActionState = { error: string | null; requestId?: string };

/**
 * Log time against a work item.
 *
 * A Server Action rather than a browser fetch, for the same reason every other
 * write is one: the session token lives in an HttpOnly cookie and is attached
 * server-side (docs/06 §1).
 *
 * None of the rules are re-implemented here — not the 24-hour ceiling, not the
 * refusal of future dates, not the per-day cap across items. The API states all
 * three and the database backs two of them; a copy on this side is a copy that
 * drifts. This function's whole job is to carry the refusal back intact.
 */
export async function logTime(
  reference: string,
  hours: string,
  loggedOn: string,
  note: string,
): Promise<TimeActionState> {
  try {
    await api(`/work-items/${reference}/time-entries`, {
      method: "POST",
      body: { hours, logged_on: loggedOn, note },
    });
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return { error: error.error.message, requestId: error.error.request_id };
    }

    return { error: "We could not reach the server. Please try again." };
  }

  // Three places show this number: the item's own total, the timesheet, and
  // whatever list the item is sitting in.
  revalidatePath(`/work/${reference}`);
  revalidatePath("/time");

  return { error: null };
}

export async function deleteTimeEntry(
  reference: string,
  entryId: string,
): Promise<TimeActionState> {
  try {
    await api(`/work-items/${reference}/time-entries/${entryId}`, { method: "DELETE" });
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return { error: error.error.message, requestId: error.error.request_id };
    }

    return { error: "We could not reach the server. Please try again." };
  }

  revalidatePath(`/work/${reference}`);
  revalidatePath("/time");

  return { error: null };
}

"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";

/**
 * Ending a session (ADR 0023).
 *
 * Both actions are about the caller's own account — there is no membership id
 * and no permission involved — so the only thing that can go wrong is a session
 * that is not theirs or has already ended, which the API answers with 404 and a
 * sentence.
 */
export type SessionResult = { error: string | null };

export async function endSession(id: string): Promise<SessionResult> {
  try {
    await api(`/auth/sessions/${id}`, { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath("/settings/sessions");

  return { error: null };
}

export async function endOtherSessions(): Promise<SessionResult> {
  try {
    await api("/auth/sessions", { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath("/settings/sessions");

  return { error: null };
}

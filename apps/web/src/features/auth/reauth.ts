"use server";

import { api, describeApiError } from "@/lib/api";

/**
 * Proving the password again, without signing in again (ADR 0034).
 *
 * `FormData`, like every other secret that crosses this boundary: Next.js
 * prints a plain argument to the development log, and a password is the last
 * thing that belongs there (ADR 0030).
 */
export async function reauthenticate(form: FormData): Promise<{ error: string | null }> {
  const password = String(form.get("password") ?? "");

  try {
    await api("/auth/reauthenticate", { method: "POST", body: { password } });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  return { error: null };
}

"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";

/**
 * Turning a second factor on and off (ADR 0030).
 *
 * Server Actions, like every mutation here: the session token lives in an
 * HttpOnly cookie and never reaches the browser (ADR 0012). It matters more
 * than usual on this screen — the enrolment secret and the recovery codes pass
 * through this process and are handed to the page as values to render once,
 * not fetched by it.
 */
export type BeginResult = {
  error: string | null;
  secret: string | null;
  uri: string | null;
};

export async function beginEnrolment(): Promise<BeginResult> {
  try {
    const { data } = await api<{ secret: string; uri: string }>("/auth/mfa", { method: "POST" });

    return { error: null, secret: data.secret, uri: data.uri };
  } catch (error) {
    return { error: describeApiError(error).error, secret: null, uri: null };
  }
}

export type ConfirmResult = { error: string | null; codes: string[] };

export async function confirmEnrolment(code: string): Promise<ConfirmResult> {
  try {
    const { data } = await api<{ recovery_codes: string[] }>("/auth/mfa/confirm", {
      method: "POST",
      body: { code },
    });

    revalidatePath("/settings/two-factor");

    return { error: null, codes: data.recovery_codes };
  } catch (error) {
    return { error: describeApiError(error).error, codes: [] };
  }
}

export type DisableResult = { error: string | null };

export async function disableTwoFactor(password: string): Promise<DisableResult> {
  try {
    await api("/auth/mfa", { method: "DELETE", body: { password } });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath("/settings/two-factor");
  revalidatePath("/settings/sessions");

  return { error: null };
}

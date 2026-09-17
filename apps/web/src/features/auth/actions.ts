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

/**
 * `FormData`, not a string — and this is not a style preference.
 *
 * Next.js logs the arguments of every Server Action it runs in development,
 * verbatim: `confirmEnrolment("685123")` and `disableTwoFactor("password")`
 * both appeared in the terminal the first time this screen was used for real,
 * which puts a live one-time code and somebody's actual password into a dev
 * log, a CI transcript and any screen share that happens to be running. A
 * `FormData` argument logs as `{}`, which is why the login form on the other
 * side of this feature never leaked anything.
 *
 * Found by running the product, not by reading it — the same way every defect
 * this phase has been found.
 */
export async function confirmEnrolment(form: FormData): Promise<ConfirmResult> {
  const code = String(form.get("code") ?? "");

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

/** `FormData` for the same reason as above: a string argument is printed. */
export async function disableTwoFactor(form: FormData): Promise<DisableResult> {
  const password = String(form.get("password") ?? "");

  try {
    await api("/auth/mfa", { method: "DELETE", body: { password } });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath("/settings/two-factor");
  revalidatePath("/settings/sessions");

  return { error: null };
}

/**
 * Ten new recovery codes, without taking the factor off first.
 *
 * The screen used to say "turn two-factor off with your password and set it up
 * again", which somebody read the first time they lost their list. That leaves
 * the account with no second factor for as long as it takes to re-scan a QR
 * code, to solve a problem that was never about the factor.
 */
export async function regenerateRecoveryCodes(form: FormData): Promise<ConfirmResult> {
  const password = String(form.get("password") ?? "");

  try {
    const { data } = await api<{ recovery_codes: string[] }>("/auth/mfa/recovery-codes", {
      method: "POST",
      body: { password },
    });

    return { error: null, codes: data.recovery_codes };
  } catch (error) {
    return { error: describeApiError(error).error, codes: [] };
  }
}

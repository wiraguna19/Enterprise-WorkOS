"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError, REAUTH_CODE } from "@/lib/api";

/**
 * Administering the organization's identity provider (ADR 0052).
 *
 * Every one of these can be refused with "confirm your password" (ADR 0034) —
 * the screen answers that with the password box in place and runs the act
 * again, so `needsPassword` comes back as a flag rather than as red text.
 */
export type SsoActionResult = { error: string | null; needsPassword?: boolean };

function failure(error: unknown): SsoActionResult {
  const described = describeApiError(error);

  return {
    error: described.code === REAUTH_CODE ? null : described.error,
    needsPassword: described.code === REAUTH_CODE,
  };
}

export async function saveSsoConnection(input: {
  idp_entity_id: string;
  idp_sso_url: string;
  idp_certificate: string;
  domains: string[];
}): Promise<SsoActionResult> {
  try {
    await api("/sso-connection", { method: "PUT", body: input });
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/settings/sso");

  return { error: null };
}

export async function setSsoEnforced(
  enforced: boolean,
): Promise<SsoActionResult & { ended?: number }> {
  let ended: number;

  try {
    const { data } = await api<{ enforced: boolean; sessions_ended: number }>(
      "/sso-connection/enforcement",
      { method: "PATCH", body: { enforced } },
    );

    ended = data.sessions_ended;
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/settings/sso");

  return { error: null, ended };
}

export async function deleteSsoConnection(): Promise<SsoActionResult> {
  try {
    await api("/sso-connection", { method: "DELETE" });
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/settings/sso");

  return { error: null };
}

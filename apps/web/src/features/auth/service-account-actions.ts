"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";
import type { TokenAccess } from "./api-token-actions";

/**
 * Service accounts (ADR 0059): members that are not people, with tokens an
 * administrator issues. Every refusal is the API's sentence.
 */
export type ServiceAccountResult = { error: string | null };

function refresh(): void {
  revalidatePath("/settings/service-accounts");
}

export async function createServiceAccount(input: { name: string; role: string }): Promise<ServiceAccountResult> {
  try {
    await api("/service-accounts", { method: "POST", body: input });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

export async function deactivateServiceAccount(id: string): Promise<ServiceAccountResult> {
  try {
    await api(`/service-accounts/${id}`, { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

export async function issueServiceToken(
  accountId: string,
  input: { name: string; access: TokenAccess; expiresInDays: number },
): Promise<ServiceAccountResult & { token?: string }> {
  let token: string;

  try {
    const { data } = await api<{ token: string }>(`/service-accounts/${accountId}/tokens`, {
      method: "POST",
      body: { name: input.name, access: input.access, expires_in_days: input.expiresInDays },
    });

    token = data.token;
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null, token };
}

export async function revokeServiceToken(accountId: string, tokenId: string): Promise<ServiceAccountResult> {
  try {
    await api(`/service-accounts/${accountId}/tokens/${tokenId}`, { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

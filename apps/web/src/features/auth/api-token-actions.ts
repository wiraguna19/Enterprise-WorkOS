"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";

/**
 * A person's own API tokens (ADR 0049).
 *
 * `createApiToken` is the only place in the product that ever returns a token's
 * value. The screen shows it once; nothing stores it on this side.
 */
export type TokenAccess = "read" | "read_write";

export type TokenResult = { error: string | null };

export async function createApiToken(input: {
  name: string;
  access: TokenAccess;
  expiresInDays: number;
}): Promise<TokenResult & { token?: string }> {
  let token: string;

  try {
    const { data } = await api<{ token: string }>("/me/api-tokens", {
      method: "POST",
      body: { name: input.name, access: input.access, expires_in_days: input.expiresInDays },
    });

    token = data.token;
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath("/settings/api-tokens");

  return { error: null, token };
}

export async function revokeApiToken(id: string): Promise<TokenResult> {
  try {
    await api(`/me/api-tokens/${id}`, { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath("/settings/api-tokens");

  return { error: null };
}

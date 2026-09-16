"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";

/**
 * Writing a role (ADR 0018).
 *
 * The refusals this surfaces are the product: a permission the author does not
 * hold, one of the four roles the product ships with, a role people are
 * holding. Each arrives as a sentence and `describeApiError` is what keeps it
 * one.
 */
export type RoleResult = { error: string | null };

export type RoleInput = {
  key?: string;
  name?: string;
  description?: string;
  permissions?: string[];
};

function refresh(): void {
  revalidatePath("/settings/roles");
}

export async function createRole(input: RoleInput): Promise<RoleResult> {
  try {
    await api("/roles", { method: "POST", body: input });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

export async function saveRole(key: string, input: RoleInput): Promise<RoleResult> {
  try {
    await api(`/roles/${key}`, { method: "PATCH", body: input });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

export async function deleteRole(key: string): Promise<RoleResult> {
  try {
    await api(`/roles/${key}`, { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh();

  return { error: null };
}

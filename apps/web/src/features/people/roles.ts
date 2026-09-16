"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";

/**
 * Granting and revoking authority over one thing (ADR 0016).
 *
 * The API refuses the grants that would not mean what they appear to — a role
 * the person already holds across the organization, a scope that does not
 * exist, your own authority — with 409 and a named reason. `describeApiError`
 * is what turns that into the sentence rather than the metadata beside it: the
 * first version of this printed `already_organization_wide manager` at the
 * person, which is a refusal code wearing a message's clothes.
 */
export type RoleResult = { error: string | null };

function failure(error: unknown): RoleResult {
  return { error: describeApiError(error).error };
}

export async function grantRole(
  membershipId: string,
  input: { role: string; scope_type: string; scope_id: string },
): Promise<RoleResult> {
  try {
    await api(`/people/${membershipId}/roles`, { method: "POST", body: input });
  } catch (error) {
    return failure(error);
  }

  revalidatePath(`/people/${membershipId}`);

  return { error: null };
}

export async function revokeRole(membershipId: string, grantId: string): Promise<RoleResult> {
  try {
    await api(`/people/${membershipId}/roles/${grantId}`, { method: "DELETE" });
  } catch (error) {
    return failure(error);
  }

  revalidatePath(`/people/${membershipId}`);

  return { error: null };
}

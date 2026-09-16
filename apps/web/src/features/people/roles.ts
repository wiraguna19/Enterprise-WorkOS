"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";

/**
 * Granting and revoking authority over one thing (ADR 0016).
 *
 * The API refuses the grants that would not mean what they appear to — a role
 * the person already holds across the organization, a scope that does not
 * exist, your own authority — with 409 and a named reason. Those sentences are
 * surfaced as they arrive: "They already hold that role across the whole
 * organization." is the useful answer, and nothing this client invented would
 * be as specific.
 */
export type RoleResult = { error: string | null };

function failure(error: unknown): RoleResult {
  if (error instanceof ApiRequestError) {
    const details = error.error.details as Record<string, string[]> | undefined;
    const refusals = details ? Object.values(details).flat() : [];

    return { error: refusals.length > 0 ? refusals.join(" ") : error.error.message };
  }

  return { error: "We could not reach the server. Please try again." };
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

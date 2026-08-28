"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";

export type MemberResult = { error: string | null };

/**
 * Team membership changes.
 *
 * The API decides who may do this — a team lead, or someone with
 * `team.manage_members` — and these functions carry its refusal back rather
 * than pre-judging it. The client already knows the answer for rendering
 * purposes, from the `permissions` block on the team, but knowing is not
 * deciding (docs/05 §3).
 */
export async function addTeamMember(teamId: string, membershipId: string): Promise<MemberResult> {
  try {
    await api(`/teams/${teamId}/members`, {
      method: "POST",
      body: { membership_id: membershipId },
    });
  } catch (error) {
    return { error: message(error) };
  }

  revalidatePath(`/teams/${teamId}`);

  return { error: null };
}

export async function removeTeamMember(
  teamId: string,
  membershipId: string,
): Promise<MemberResult> {
  try {
    await api(`/teams/${teamId}/members/${membershipId}`, { method: "DELETE" });
  } catch (error) {
    return { error: message(error) };
  }

  revalidatePath(`/teams/${teamId}`);

  return { error: null };
}

function message(error: unknown): string {
  if (error instanceof ApiRequestError) return error.error.message;

  return "We could not reach the server. Please try again.";
}

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

/**
 * Taking one permission away, and giving it back (ADR 0020).
 *
 * A denial beats every grant, so the API refuses the one that cannot be undone
 * — denying `role.manage` to the last person who could lift it — and the
 * sentence it sends back is the whole explanation the screen has. Same
 * treatment as a refused grant: `describeApiError`, never the code.
 */
export async function denyPermission(
  membershipId: string,
  input: { permission: string; scope_type: string | null; scope_id: string | null; reason: string },
): Promise<RoleResult> {
  try {
    await api(`/people/${membershipId}/denials`, { method: "POST", body: input });
  } catch (error) {
    return failure(error);
  }

  revalidatePath(`/people/${membershipId}`);

  return { error: null };
}

export async function liftDenial(membershipId: string, denialId: string): Promise<RoleResult> {
  try {
    await api(`/people/${membershipId}/denials/${denialId}`, { method: "DELETE" });
  } catch (error) {
    return failure(error);
  }

  revalidatePath(`/people/${membershipId}`);

  return { error: null };
}

export type Explanation = {
  permission: string;
  allowed: boolean;
  granted_by: string[];
  granted_on: Array<{ role: string; scope_type: string; scope_name: string | null }>;
  denied_by: Array<{ scope_type: string | null; scope_name: string | null; reason: string }>;
};

/**
 * "Why can't they do that."
 *
 * A read, but a server action rather than a page load: the answer is asked for
 * one permission at a time, by somebody already on this screen, and putting it
 * in the URL would make the question a place you can be linked to.
 */
export async function explainPermission(
  membershipId: string,
  permission: string,
): Promise<{ error: string | null; explanation: Explanation | null }> {
  try {
    const { data } = await api<Explanation>(
      `/people/${membershipId}/permissions/explain?permission=${encodeURIComponent(permission)}`,
    );

    return { error: null, explanation: data };
  } catch (error) {
    return { ...failure(error), explanation: null };
  }
}

/**
 * "Delete my data" (ADR 0022).
 *
 * Lives beside the role actions because it is the same screen and the same
 * permission family, and because what it removes first is authority. The API
 * refuses the two erasures that would not mean what they say — a second one,
 * and an account shared with another organization — with 409 and a sentence.
 */
export async function erasePerson(membershipId: string): Promise<RoleResult> {
  try {
    await api(`/people/${membershipId}/erase`, { method: "POST" });
  } catch (error) {
    return failure(error);
  }

  revalidatePath(`/people/${membershipId}`);
  revalidatePath("/people");

  return { error: null };
}

/**
 * Taking a lost second factor off somebody's account (ADR 0031).
 *
 * No password field here, unlike the self-service path: the administrator is
 * not proving anything about themselves, they are acting on somebody else under
 * a permission and an audit entry that carries their name.
 */
export async function revokeMfa(membershipId: string): Promise<{ error: string | null }> {
  try {
    await api(`/people/${membershipId}/mfa`, { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath(`/people/${membershipId}`);

  return { error: null };
}

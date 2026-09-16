"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";

/**
 * Inviting somebody in (ADR 0017).
 *
 * The link comes back once and is never retrievable again — only its digest is
 * stored, because a token that creates an account is a credential. The form
 * shows it and says so; losing it means revoking and inviting again.
 */
export type Invitation = {
  id: string;
  token: string;
  email: string;
  expires_at: string;
};

export type InviteResult = { error: string | null; invitation?: Invitation };

export async function invitePerson(input: {
  email: string;
  role: string | null;
}): Promise<InviteResult> {
  let invitation: Invitation;

  try {
    const { data } = await api<Invitation>("/people/invite", {
      method: "POST",
      // A blank role is not "no role" to a validator, and this one is
      // deliberately optional: somebody can be invited before anyone has
      // decided what they will do.
      body: input.role ? { email: input.email, role: input.role } : { email: input.email },
    });

    invitation = data;
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath("/people");

  return { error: null, invitation };
}

export async function revokeInvitation(id: string): Promise<{ error: string | null }> {
  try {
    await api(`/invitations/${id}`, { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  revalidatePath("/people");

  return { error: null };
}

/**
 * Accepting one. No session exists yet, which is the whole point: this is the
 * only write in the product made by somebody the product has never met.
 */
export async function acceptInvitation(
  token: string,
  input: { name: string; password: string },
): Promise<{ error: string | null }> {
  try {
    await api(`/invitations/${token}/accept`, { method: "POST", body: input });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  return { error: null };
}

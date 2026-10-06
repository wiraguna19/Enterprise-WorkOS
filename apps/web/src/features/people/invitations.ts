"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";
import { requestLocale } from "@/i18n/server";

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
 * What an invitation link points at: the organization and the address, and no
 * more. `null` for a token that is wrong, expired, revoked or used — all four
 * the same, as the API answers them.
 *
 * The token travels in a POST body. It used to be in the API path, and so in
 * every access log between here and the database.
 */
export async function previewInvitation(
  form: FormData,
): Promise<{ organization: string; email: string } | null> {
  const token = String(form.get("token") ?? "");

  if (token === "") return null;

  return api<{ organization: string; email: string }>("/invitations/preview", {
    method: "POST",
    body: { token },
  })
    .then((r) => r.data)
    .catch(() => null);
}

/**
 * Accepting one. No session exists yet, which is the whole point: this is the
 * only write in the product made by somebody the product has never met.
 *
 * `FormData`, because the token and the password are exactly what must not be
 * printed in a development log (see `features/auth/actions.ts`).
 */
export async function acceptInvitation(form: FormData): Promise<{ error: string | null }> {
  try {
    // The language the page was read in becomes the new account's, so the
    // first screen after signing in speaks the same language as the invite.
    await api("/invitations/accept", {
      method: "POST",
      body: {
        token: String(form.get("token") ?? ""),
        name: String(form.get("name") ?? ""),
        password: String(form.get("password") ?? ""),
        locale: await requestLocale(),
      },
    });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  return { error: null };
}

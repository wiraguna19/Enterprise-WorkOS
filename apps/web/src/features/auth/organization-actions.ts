"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { api, describeApiError } from "@/lib/api";
import { setSessionToken } from "@/lib/session";

/**
 * Switching organization (docs/06 §1, ADR 0050).
 *
 * The API answers a switch the way it answers a sign-in — a new token for a new
 * session — and this swaps the cookie. The browser never holds either token;
 * it sees a redirect and a page from the other organization.
 */
export type OrganizationChoice = { id: string; name: string; slug: string; current: boolean };

export async function listOrganizations(): Promise<{
  error: string | null;
  organizations?: OrganizationChoice[];
}> {
  try {
    const { data } = await api<OrganizationChoice[]>("/auth/organizations");

    return { error: null, organizations: data };
  } catch (error) {
    return { error: describeApiError(error).error };
  }
}

export async function switchOrganization(organizationId: string): Promise<{ error: string | null }> {
  try {
    const { data } = await api<{ token: string; expires_at: string }>("/auth/organization", {
      method: "POST",
      body: { organization_id: organizationId },
    });

    await setSessionToken(data.token, data.expires_at);
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  // Everything cached was rendered for the organization just left — its
  // projects, its inbox badge, its sidebar. Home, freshly, rather than the
  // same path, which may name something that does not exist over there.
  revalidatePath("/", "layout");
  redirect("/");
}

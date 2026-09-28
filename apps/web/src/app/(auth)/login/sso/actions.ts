"use server";

import { randomBytes } from "node:crypto";
import { redirect } from "next/navigation";
import { api, ApiRequestError } from "@/lib/api";
import { safeNextPath } from "@/lib/next-path";
import { setSsoBinding } from "@/lib/session";

export type SsoStartState = { error: string | null; requestId?: string };

/**
 * The first step of signing in through an identity provider (ADR 0052).
 *
 * The binding is made HERE, on the server, and never reaches the page: it goes
 * into an HttpOnly cookie and, to the API, once. The API keeps only its digest,
 * and will not finish the sign-in for a browser that cannot show it — which is
 * what stops an identity provider's answer captured in one browser from being
 * replayed into another.
 */
export async function startSingleSignOn(
  _previous: SsoStartState,
  formData: FormData,
): Promise<SsoStartState> {
  const email = String(formData.get("email") ?? "");
  const next = safeNextPath(String(formData.get("next") ?? "/"));
  const binding = randomBytes(32).toString("base64url");

  let destination: string;

  try {
    const { data } = await api<{ redirect_url: string }>("/auth/sso/start", {
      method: "POST",
      body: { email, binding },
      anonymous: true,
    });

    destination = data.redirect_url;
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return { error: error.error.message, requestId: error.error.request_id };
    }

    return { error: "We could not reach the server. Please try again." };
  }

  await setSsoBinding(binding, next);

  // To the identity provider, outside this app. The one redirect in the
  // product whose target comes from the API rather than from a path this app
  // owns — and it is the organization's own configured IdP, never anything
  // from the request.
  redirect(destination);
}

"use server";

import { redirect } from "next/navigation";
import { api, ApiRequestError } from "@/lib/api";
import { setSessionToken } from "@/lib/session";

export type LoginState = { error: string | null; requestId?: string };

/**
 * The token crosses the network once, server to server, and is written into an
 * HttpOnly cookie. It is never returned to the browser as JSON (docs/06 §1).
 */
export async function login(
  _previous: LoginState,
  formData: FormData,
): Promise<LoginState> {
  const email = String(formData.get("email") ?? "");
  const password = String(formData.get("password") ?? "");

  try {
    const { data } = await api<{ token: string; expires_at: string }>("/auth/login", {
      method: "POST",
      body: { email, password },
      anonymous: true,
    });

    await setSessionToken(data.token, data.expires_at);
  } catch (error) {
    if (error instanceof ApiRequestError) {
      // The API deliberately returns an identical message for "wrong password"
      // and "unknown account"; surfacing it verbatim keeps that property.
      return { error: error.error.message, requestId: error.error.request_id };
    }

    return { error: "We could not reach the server. Please try again." };
  }

  redirect("/");
}

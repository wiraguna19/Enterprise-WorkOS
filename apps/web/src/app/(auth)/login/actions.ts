"use server";

import { redirect } from "next/navigation";
import { api, ApiRequestError } from "@/lib/api";
import {
  clearMfaChallenge,
  getMfaChallenge,
  setMfaChallenge,
  setSessionToken,
} from "@/lib/session";

export type LoginState = {
  error: string | null;
  requestId?: string;
  /** The password was right and the account has a second factor (ADR 0030). */
  mfaRequired?: boolean;
};

/**
 * What the API answers to a sign-in: a session, or a challenge.
 *
 * Mirrors the server's discriminated pair rather than a token that is
 * sometimes missing, so neither side can forget to look.
 */
type LoginPayload =
  | { mfa_required: true; challenge: string }
  | { mfa_required?: false; token: string; expires_at: string };

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
    const { data } = await api<LoginPayload>("/auth/login", {
      method: "POST",
      body: { email, password },
      anonymous: true,
    });

    if (data.mfa_required) {
      // The challenge goes into an HttpOnly cookie, not back to the form. It
      // is not a session, but it is half of one, and nothing about
      // authenticating belongs in the browser's JavaScript heap (ADR 0012).
      await setMfaChallenge(data.challenge);

      return { error: null, mfaRequired: true };
    }

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

/**
 * The second half of the sign-in: six digits from the app, or a recovery code.
 *
 * The challenge is read from the cookie rather than the form, so a page left
 * open past its two minutes fails as "expired" instead of posting a challenge
 * the server will refuse for reasons the person cannot see.
 */
export async function verifyMfa(
  _previous: LoginState,
  formData: FormData,
): Promise<LoginState> {
  const code = String(formData.get("code") ?? "");
  const challenge = await getMfaChallenge();

  if (challenge === null) {
    return { error: "This sign-in has expired. Please start again." };
  }

  try {
    const { data } = await api<{ token: string; expires_at: string }>("/auth/mfa/verify", {
      method: "POST",
      body: { challenge, code },
      anonymous: true,
    });

    await setSessionToken(data.token, data.expires_at);
    await clearMfaChallenge();
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return {
        error: error.error.message,
        requestId: error.error.request_id,
        // Stay on the code prompt: a wrong code is a retry, not a reason to
        // make somebody type their password again.
        mfaRequired: true,
      };
    }

    return { error: "We could not reach the server. Please try again.", mfaRequired: true };
  }

  redirect("/");
}

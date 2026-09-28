import { NextResponse } from "next/server";
import { api, ApiRequestError } from "@/lib/api";

/**
 * The assertion consumer service: where an identity provider posts its answer
 * (ADR 0052).
 *
 * Here and not on the API because this server sets the session cookie
 * (docs/06 §1) — the browser is sent back to THIS origin, and the API is
 * never addressed by a browser at all.
 *
 * It does NOT sign anybody in. The post arrives cross-site, so the binding
 * cookie that says which browser started the sign-in is not sent with it
 * (`SameSite=Lax`). The API verifies the answer and hands back a one-minute
 * code; this route turns that into a same-site redirect to /complete, where
 * the cookie IS sent and the browser can prove it is the one that started.
 * 303, so the browser follows with a GET and does not re-post the answer.
 */
export async function POST(request: Request) {
  const form = await request.formData();
  const samlResponse = form.get("SAMLResponse");
  const relayState = form.get("RelayState");

  if (typeof samlResponse !== "string" || typeof relayState !== "string") {
    return back(request, "auth.sso_failed");
  }

  try {
    const { data } = await api<{ completion: string }>("/auth/sso/acs", {
      method: "POST",
      body: { saml_response: samlResponse, relay_state: relayState },
      anonymous: true,
    });

    const complete = new URL("/api/auth/sso/complete", request.url);
    complete.searchParams.set("c", data.completion);

    return NextResponse.redirect(complete, 303);
  } catch (error) {
    return back(request, error instanceof ApiRequestError ? error.error.code : "auth.sso_failed");
  }
}

/** To the SSO page with a code — never a sentence — for it to explain. */
function back(request: Request, code: string) {
  const page = new URL("/login/sso", request.url);
  page.searchParams.set("error", code);

  return NextResponse.redirect(page, 303);
}

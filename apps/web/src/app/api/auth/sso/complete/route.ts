import { NextResponse } from "next/server";
import { api, ApiRequestError } from "@/lib/api";
import { safeNextPath } from "@/lib/next-path";
import { clearSsoBinding, getSsoBinding, setSessionToken } from "@/lib/session";

/**
 * The last step of single sign-on: the same browser, proving it (ADR 0052).
 *
 * Reached by a same-site redirect from /acs, so the binding cookie set when
 * the sign-in started comes with it. The API issues a session only if the
 * binding matches the one it was given at the start — a browser that did not
 * start this sign-in cannot finish it, whatever answer it was made to post.
 *
 * The token crosses once, server to server, into the HttpOnly cookie, exactly
 * like a password sign-in (docs/06 §1).
 */
export async function GET(request: Request) {
  const completion = new URL(request.url).searchParams.get("c");
  const bound = await getSsoBinding();

  // Spent either way: a binding is for one attempt.
  await clearSsoBinding();

  if (!completion || bound === null) {
    return back(request, "auth.sso_expired");
  }

  try {
    const { data } = await api<{ token: string; expires_at: string }>("/auth/sso/complete", {
      method: "POST",
      body: { completion, binding: bound.binding },
      anonymous: true,
    });

    await setSessionToken(data.token, data.expires_at);
  } catch (error) {
    return back(request, error instanceof ApiRequestError ? error.error.code : "auth.sso_failed");
  }

  // Validated again although it was validated when it was stored: it came
  // back out of a cookie, and a cookie is an input (ADR 0032).
  return NextResponse.redirect(new URL(safeNextPath(bound.next), request.url), 303);
}

function back(request: Request, code: string) {
  const page = new URL("/login/sso", request.url);
  page.searchParams.set("error", code);

  return NextResponse.redirect(page, 303);
}

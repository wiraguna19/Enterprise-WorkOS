import { NextResponse } from "next/server";
import { getSessionToken } from "@/lib/session";

/**
 * Channel authorization, proxied (docs/06 §1, docs/07 §8).
 *
 * The browser cannot call the API's /broadcasting/auth itself: the session
 * token lives in an HttpOnly cookie and never enters the JavaScript heap, which
 * is the whole point of storing it that way. So Echo asks this route, which
 * attaches the bearer token server-side — the same shape every other write in
 * this app already uses.
 *
 * It forwards rather than decides. The API owns who may hear what, and a second
 * opinion here would be a copy of a rule that will drift.
 */

const API_ORIGIN = (process.env.API_URL ?? "http://localhost:8000/api/v1").replace(
  /\/api\/v1\/?$/,
  "",
);

export async function POST(request: Request) {
  const token = await getSessionToken();

  if (!token) {
    return NextResponse.json({ error: "unauthenticated" }, { status: 401 });
  }

  // Echo posts form-encoded; passed through unread. This route does not need to
  // understand socket_id or channel_name, and parsing them would invite the
  // temptation to validate them here instead of at the API.
  const body = await request.text();

  const response = await fetch(`${API_ORIGIN}/broadcasting/auth`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": request.headers.get("content-type") ?? "application/x-www-form-urlencoded",
      Accept: "application/json",
    },
    body,
    cache: "no-store",
  });

  // The status is passed through unchanged: a 403 from the API is a refused
  // subscription, and Echo needs to see it as one rather than as a transport
  // failure it should retry.
  return new NextResponse(await response.text(), {
    status: response.status,
    headers: { "Content-Type": response.headers.get("content-type") ?? "application/json" },
  });
}

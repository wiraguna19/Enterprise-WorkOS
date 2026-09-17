import { NextResponse, type NextRequest } from "next/server";
import { SESSION_COOKIE } from "@/lib/session-cookie";

/**
 * Proxy — redirects unauthenticated traffic to the login screen.
 *
 * Renamed from middleware.ts: Next.js 16 deprecated that convention.
 *
 * This is a UX shortcut, NOT a security boundary: it only checks that a cookie
 * is present, never that it is valid. Every real authorization decision happens
 * in the API (docs/06 §2). Deleting this file must not make anything
 * accessible that was not accessible before.
 */
/**
 * `/invite` is here because the person holding an invitation link has no
 * account yet — bouncing them to a sign-in they cannot pass is the one way to
 * make an invitation useless, and it is what this file did until flow 2 walked
 * the link as a stranger. The other three were always public for the same
 * reason: they are the ways IN.
 */
const PUBLIC_PATHS = ["/login", "/forgot-password", "/reset-password", "/invite"];

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  // The path, forwarded to the server components (ADR 0033). A layout cannot
  // ask which page is rendering inside it, and the one confinement this app
  // has — an organization that requires a second factor — needs exactly that
  // to avoid redirecting the enrolment screen to itself. Set here because this
  // is the only place that sees the request before Next.js routes it.
  const headers = new Headers(request.headers);
  headers.set("x-pathname", pathname);

  const forward = { request: { headers } };

  if (PUBLIC_PATHS.some((path) => pathname.startsWith(path))) {
    return NextResponse.next(forward);
  }

  const hasSession = request.cookies.has(SESSION_COOKIE);

  if (!hasSession) {
    const login = new URL("/login", request.url);
    login.searchParams.set("next", pathname);

    return NextResponse.redirect(login);
  }

  return NextResponse.next(forward);
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|webp)$).*)"],
};

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
const PUBLIC_PATHS = ["/login", "/forgot-password", "/reset-password"];

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  if (PUBLIC_PATHS.some((path) => pathname.startsWith(path))) {
    return NextResponse.next();
  }

  const hasSession = request.cookies.has(SESSION_COOKIE);

  if (!hasSession) {
    const login = new URL("/login", request.url);
    login.searchParams.set("next", pathname);

    return NextResponse.redirect(login);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|webp)$).*)"],
};

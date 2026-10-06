import type { NextConfig } from "next";

/**
 * Headers every page carries.
 *
 * None of these is a feature; each closes a door that is open by default:
 *
 * - **Framing** (`frame-ancestors 'none'`, and `X-Frame-Options` for browsers
 *   that predate it): nothing here is meant to be embedded, and a page that
 *   can be framed can be clicked through an invisible overlay — "approve",
 *   "erase", "stop enforcing single sign-on".
 * - **`nosniff`**: an uploaded file is served as what it says it is, not as
 *   whatever its bytes look like.
 * - **Referrer**: an invitation link carries its token in the path; a page
 *   opened from it must not hand that path to another site.
 * - **Permissions**: the product uses no camera, microphone or location, so a
 *   script injected into it cannot ask for them either.
 * - **HSTS** in production only — on `http://localhost` it would do nothing
 *   useful and, on a shared development hostname, something unwelcome.
 *
 * Not a full script CSP. Next.js inlines its bootstrap scripts, and a policy
 * that allows `'unsafe-inline'` scripts protects little while a nonce-based
 * one is its own piece of work; `base-uri` and `object-src` are the part that
 * costs nothing.
 */
const securityHeaders = [
  {
    key: "Content-Security-Policy",
    value: "frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
  },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=(), payment=()" },
  ...(process.env.NODE_ENV === "production"
    ? [{ key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" }]
    : []),
];

const nextConfig: NextConfig = {
  poweredByHeader: false,
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
};

export default nextConfig;

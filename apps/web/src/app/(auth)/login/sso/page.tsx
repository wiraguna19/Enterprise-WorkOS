import type { Metadata } from "next";
import { safeNextPath } from "@/lib/next-path";
import { SsoForm } from "./SsoForm";

export const metadata: Metadata = { title: "Single sign-on — Work OS" };

/**
 * What a failed round trip comes back with (ADR 0052).
 *
 * The route handlers send a CODE, never a sentence: a message taken from the
 * URL and printed on a sign-in page is a way to put words of anybody's
 * choosing on this product's letterhead. Anything not listed reads as the
 * generic failure.
 */
const FAILURES: Record<string, string> = {
  "auth.sso_expired": "That sign-in took too long or was already used. Please start again.",
  "auth.sso_failed":
    "Your identity provider's answer could not be accepted. Try again, and if it keeps happening, tell your administrator.",
  "auth.sso_no_account":
    "There is no account here for that address. Ask your administrator for an invitation.",
};

const GENERIC = "Single sign-on did not complete. Please start again.";

export default async function SsoLoginPage({
  searchParams,
}: {
  searchParams: Promise<{ next?: string | string[]; error?: string | string[] }>;
}) {
  const { next, error } = await searchParams;
  const code = Array.isArray(error) ? error[0] : error;

  return (
    <main className="flex min-h-dvh items-center justify-center px-4">
      <div className="w-full max-w-sm">
        <div className="mb-8">
          <div className="mb-6 flex items-center gap-2">
            <span className="flex size-6 items-center justify-center rounded-sm bg-n-900 text-micro font-bold text-n-0">
              W
            </span>
            <span className="text-body font-semibold text-n-900">Work OS</span>
          </div>
          <h1 className="text-h1 font-semibold text-n-900">Single sign-on</h1>
          <p className="mt-1 text-body text-n-500">
            Sign in through your organization&apos;s identity provider.
          </p>
        </div>

        <SsoForm
          next={safeNextPath(Array.isArray(next) ? next[0] : next)}
          failure={code === undefined ? null : (FAILURES[code] ?? GENERIC)}
        />
      </div>
    </main>
  );
}

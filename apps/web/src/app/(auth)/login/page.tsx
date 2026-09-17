import type { Metadata } from "next";
import { safeNextPath } from "@/lib/next-path";
import { LoginForm } from "./LoginForm";

export const metadata: Metadata = { title: "Sign in — Work OS" };

/**
 * Deliberately plain. A login screen is a door, not a landing page: no hero,
 * no gradient, no product marketing (docs/09 §1).
 */
/**
 * `searchParams` is a promise in Next 16, and the `next` the proxy wrote is
 * read HERE rather than in the client component: the value is validated on the
 * server before it is ever rendered into the page (ADR 0032).
 */
export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ next?: string | string[] }>;
}) {
  const { next } = await searchParams;

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
          <h1 className="text-h1 font-semibold text-n-900">Sign in</h1>
          <p className="mt-1 text-body text-n-500">Use your organization account.</p>
        </div>

        <LoginForm next={safeNextPath(Array.isArray(next) ? next[0] : next)} />
      </div>
    </main>
  );
}

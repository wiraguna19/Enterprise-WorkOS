import type { Metadata } from "next";
import { LoginForm } from "./LoginForm";

export const metadata: Metadata = { title: "Sign in — Work OS" };

/**
 * Deliberately plain. A login screen is a door, not a landing page: no hero,
 * no gradient, no product marketing (docs/09 §1).
 */
export default function LoginPage() {
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

        <LoginForm />
      </div>
    </main>
  );
}

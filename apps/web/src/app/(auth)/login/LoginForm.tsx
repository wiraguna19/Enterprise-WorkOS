"use client";

import { useActionState } from "react";
import { useFormStatus } from "react-dom";
import { Button } from "@/components/ui/Button";
import { login, verifyMfa, type LoginState } from "./actions";

const INITIAL: LoginState = { error: null };

export function LoginForm({ next }: { next: string }) {
  const [state, formAction] = useActionState(login, INITIAL);

  // The code prompt replaces the password form rather than appearing beneath
  // it (ADR 0030). Two forms on screen, one of them already answered, is an
  // invitation to type the password again into a field that is no longer
  // listening.
  if (state.mfaRequired) {
    return <CodeForm next={next} />;
  }

  return (
    <form action={formAction} className="space-y-4">
      {state.error && (
        <div
          role="alert"
          className="rounded-sm border border-s-danger/30 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger"
        >
          {state.error}
        </div>
      )}

      {/* Where the proxy was taking them when the session ran out. Validated
          on the server before it reached this page, and validated again in the
          action — a hidden field is an input like any other (ADR 0032). */}
      <input type="hidden" name="next" value={next} />

      <Field label="Email" name="email" type="email" autoComplete="username" required />
      <Field
        label="Password"
        name="password"
        type="password"
        autoComplete="current-password"
        required
      />

      <Submit />

      <p className="pt-2 text-caption text-n-500">
        <a href="/forgot-password" className="text-a-500 hover:text-a-700 hover:underline">
          Forgot your password?
        </a>
      </p>
    </form>
  );
}

function CodeForm({ next }: { next: string }) {
  const [state, formAction] = useActionState(verifyMfa, INITIAL);

  return (
    <form action={formAction} className="space-y-4">
      {/* Carried across the code prompt too: a sign-in interrupted by a second
          factor is still the same sign-in, and dropping the destination here
          would make two-factor the reason somebody lands on the wrong page. */}
      <input type="hidden" name="next" value={next} />
      <div>
        <h2 className="text-h2 font-semibold text-n-900">Enter your code</h2>
        <p className="mt-1 text-body-sm text-n-500">
          Six digits from your authenticator app. If you have lost the device, use one of the
          recovery codes you saved.
        </p>
      </div>

      {state.error && (
        <div
          role="alert"
          className="rounded-sm border border-s-danger/30 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger"
        >
          {state.error}
        </div>
      )}

      {/* `one-time-code` is what makes a phone offer the code from its
          notification, and `inputMode` brings up the number pad. Neither is
          decoration: this is a field people fill in while holding a second
          device. */}
      <Field
        label="Code"
        name="code"
        type="text"
        inputMode="numeric"
        autoComplete="one-time-code"
        autoFocus
        required
      />

      <Submit label="Verify" pendingLabel="Checking…" />

      <p className="pt-2 text-caption text-n-500">
        <a href="/login" className="text-a-500 hover:text-a-700 hover:underline">
          Start again
        </a>
      </p>
    </form>
  );
}

function Submit({
  label = "Sign in",
  pendingLabel = "Signing in…",
}: {
  label?: string;
  pendingLabel?: string;
}) {
  // Disabled while pending so a double submit cannot create two sessions.
  const { pending } = useFormStatus();

  return (
    <Button type="submit" variant="primary" size="lg" className="w-full" disabled={pending}>
      {pending ? pendingLabel : label}
    </Button>
  );
}

function Field({
  label,
  name,
  ...props
}: { label: string; name: string } & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <div>
      <label htmlFor={name} className="mb-1 block text-caption font-medium text-n-700">
        {label}
      </label>
      <input
        id={name}
        name={name}
        {...props}
        className="h-9 w-full rounded-sm border border-n-200 bg-n-0 px-2.5 text-body text-n-900 outline-none transition-colors duration-[120ms] placeholder:text-n-300 focus:border-a-500"
      />
    </div>
  );
}

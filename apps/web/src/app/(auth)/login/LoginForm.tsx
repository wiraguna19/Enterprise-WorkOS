"use client";

import { useActionState } from "react";
import { useFormStatus } from "react-dom";
import { Button } from "@/components/ui/Button";
import { login, type LoginState } from "./actions";

const INITIAL: LoginState = { error: null };

export function LoginForm() {
  const [state, formAction] = useActionState(login, INITIAL);

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

function Submit() {
  // Disabled while pending so a double submit cannot create two sessions.
  const { pending } = useFormStatus();

  return (
    <Button type="submit" variant="primary" size="lg" className="w-full" disabled={pending}>
      {pending ? "Signing in…" : "Sign in"}
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

"use client";

import { useActionState } from "react";
import { useFormStatus } from "react-dom";
import { Button } from "@/components/ui/Button";
import { INPUT } from "@/components/ui/Field";
import { useT } from "@/i18n/I18nProvider";
import { login, verifyMfa, type LoginState } from "./actions";

const INITIAL: LoginState = { error: null };

export function LoginForm({ next }: { next: string }) {
  const t = useT();
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

      <Field label={t("login.email")} name="email" type="email" autoComplete="username" required />
      <Field
        label={t("login.password")}
        name="password"
        type="password"
        autoComplete="current-password"
        required
      />

      <Submit />

      <p className="flex justify-between gap-4 pt-2 text-caption text-n-500">
        <a href="/forgot-password" className="text-a-500 hover:text-a-700 hover:underline">
          {t("login.forgot")}
        </a>
        {/* A link, not a second button on this form: single sign-on asks for
            an address and nothing else, and a password typed on the way
            there would go nowhere (ADR 0052). The destination travels with
            it, so an SSO sign-in lands where a password one would. */}
        <a
          href={next === "/" ? "/login/sso" : `/login/sso?next=${encodeURIComponent(next)}`}
          className="text-a-500 hover:text-a-700 hover:underline"
        >
          {t("login.sso")}
        </a>
      </p>
    </form>
  );
}

function CodeForm({ next }: { next: string }) {
  const t = useT();
  const [state, formAction] = useActionState(verifyMfa, INITIAL);

  return (
    <form action={formAction} className="space-y-4">
      {/* Carried across the code prompt too: a sign-in interrupted by a second
          factor is still the same sign-in, and dropping the destination here
          would make two-factor the reason somebody lands on the wrong page. */}
      <input type="hidden" name="next" value={next} />
      <div>
        <h2 className="text-h2 font-semibold text-n-900">{t("login.code.title")}</h2>
        <p className="mt-1 text-body-sm text-n-500">{t("login.code.body")}</p>
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
        label={t("login.code.label")}
        name="code"
        type="text"
        inputMode="numeric"
        autoComplete="one-time-code"
        autoFocus
        required
      />

      <Submit label={t("login.code.submit")} pendingLabel={t("login.code.submitting")} />

      <p className="pt-2 text-caption text-n-500">
        <a href="/login" className="text-a-500 hover:text-a-700 hover:underline">
          {t("login.code.restart")}
        </a>
      </p>
    </form>
  );
}

function Submit({
  label,
  pendingLabel,
}: {
  label?: string;
  pendingLabel?: string;
}) {
  const t = useT();
  // Disabled while pending so a double submit cannot create two sessions.
  const { pending } = useFormStatus();

  return (
    <Button type="submit" variant="primary" size="lg" className="w-full" disabled={pending}>
      {pending ? (pendingLabel ?? t("login.submitting")) : (label ?? t("login.submit"))}
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
        // The shared input. The sign-in screen had its own, a shade lighter and
        // with a different focus ring, which is exactly the "matches on three
        // screens and not the fourth" this constant exists to stop.
        className={INPUT}
      />
    </div>
  );
}

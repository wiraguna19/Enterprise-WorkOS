"use client";

import { useActionState } from "react";
import { useFormStatus } from "react-dom";
import { Button } from "@/components/ui/Button";
import { INPUT } from "@/components/ui/Field";
import { startSingleSignOn, type SsoStartState } from "./actions";
import { useT } from "@/i18n/I18nProvider";

const INITIAL: SsoStartState = { error: null };

/**
 * An address, and nothing else: the domain says which organization, and the
 * organization says which identity provider (ADR 0052). No password field —
 * a password typed here would go nowhere, and a form that asks for one teaches
 * people to type it wherever they are asked.
 */
export function SsoForm({ next, failure }: { next: string; failure: string | null }) {
  const [state, formAction] = useActionState(startSingleSignOn, INITIAL);
  const error = state.error ?? failure;
  const t = useT();

  return (
    <form action={formAction} className="space-y-4">
      {error && (
        <div
          role="alert"
          className="rounded-sm border border-s-danger/30 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger"
        >
          {error}
        </div>
      )}

      <input type="hidden" name="next" value={next} />

      <div>
        <label htmlFor="email" className="mb-1 block text-caption font-medium text-n-700">
          {t("ssologin.email")}
        </label>
        <input
          id="email"
          name="email"
          type="email"
          autoComplete="username"
          autoFocus
          required
          className={INPUT}
        />
      </div>

      <Submit />

      <p className="pt-2 text-caption text-n-500">
        <a href="/login" className="text-a-500 hover:text-a-700 hover:underline">
          {t("ssologin.password")}
        </a>
      </p>
    </form>
  );
}

function Submit() {
  const { pending } = useFormStatus();
  const t = useT();

  return (
    <Button type="submit" variant="primary" size="lg" className="w-full" disabled={pending}>
      {pending ? t("ssologin.finding") : t("ssologin.continue")}
    </Button>
  );
}

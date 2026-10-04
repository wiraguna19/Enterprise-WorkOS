"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ConfirmPassword } from "@/components/ui/ConfirmPassword";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { setMfaPolicy } from "./actions";
import { useLocale, useT } from "@/i18n/I18nProvider";

/**
 * Requiring a second factor of everybody in this organization (ADR 0033).
 *
 * The screen has to carry one idea that the switch itself cannot: **nobody is
 * signed out and nobody is locked out.** A person without a factor keeps their
 * session and can do four things with it — say who they are, sign out, start
 * enrolling, finish. Everything else waits until they have.
 *
 * The number of people that applies to is named before the button is pressed,
 * and again after, because it is the difference between a setting and a
 * consequence — and here the consequence lands on other people's afternoons.
 */
export function MfaPolicyForm({
  required,
  peopleWithout,
  editable,
}: {
  required: boolean;
  peopleWithout: number;
  editable: boolean;
}) {
  const [error, setError] = useState<string | null>(null);
  const [needsPassword, setNeedsPassword] = useState(false);
  const [busy, startAction] = useTransition();
  const toast = useToast();
  const t = useT();
  const locale = useLocale();

  const save = () =>
    startAction(async () => {
      const result = await setMfaPolicy(!required);

      setNeedsPassword(result.needsPassword === true);
      setError(result.needsPassword === true ? null : result.error);

      if (result.error === null) {
        toast({
          tone: required ? "removed" : "done",
          message: required
            ? t("mfapol.optionalAgain")
            : result.confined > 0
              ? t.plural("mfapol.requiredPending", result.confined)
              : t("mfapol.requiredAll"),
        });
      }
    });

  return (
    <Panel
      id="mfa-policy"
      title={t("settings.twoFactor.label")}
      description={t("mfapol.description")}
      actions={
        required ? (
          <Badge tone="success" icon="check">{t("mfapol.badge.required")}</Badge>
        ) : (
          <Badge tone="neutral" icon="minus">{t("mfapol.badge.optional")}</Badge>
        )
      }
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <div className="max-w-prose space-y-3">
        <p className="text-body-sm text-n-700">
          {required
            ? t("mfapol.body.required")
            : t("mfapol.body.optional")}
        </p>

        {!required && peopleWithout > 0 && (
          <p className="rounded-md border border-s-active/30 bg-s-active/10 px-3 py-2 text-body-sm text-s-active">
            {t.plural("mfapol.without", peopleWithout)}
          </p>
        )}

        {editable && (
          <Button
            variant={required ? "destructive" : "affirmative"}
            size="sm"
            disabled={busy}
            onClick={save}
          >
            {busy ? t("common.saving") : required ? t("mfapol.stop") : t("mfapol.require")}
          </Button>
        )}

        {needsPassword && (
          <ConfirmPassword
            action={required ? t("mfapol.confirm.stop") : t("mfapol.confirm.require")}
            locale={locale}
            onConfirmed={() => {
              setNeedsPassword(false);
              save();
            }}
          />
        )}
      </div>
    </Panel>
  );
}

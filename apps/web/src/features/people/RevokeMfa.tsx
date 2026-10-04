"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ConfirmPassword } from "@/components/ui/ConfirmPassword";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { useLocale, useT } from "@/i18n/I18nProvider";
import { revokeMfa } from "./roles";

/**
 * Unlocking somebody who has lost their phone (ADR 0031).
 *
 * Offered only to whoever may take this person's access away, and only when
 * there is something to take off — the API sends `mfa_enabled` to nobody else,
 * so this section does not exist for a reader who could not act on it.
 *
 * It says what it costs before it is pressed. Removing the factor does not sign
 * the person out and does not touch their password; it means their password
 * alone gets them in again, until they set a new factor up. An administrator
 * who thinks this is harmless will use it on a phone call from somebody they
 * have not identified, which is the actual attack this control is exposed to.
 */
export function RevokeMfa({
  membershipId,
  name,
  enabled,
}: {
  membershipId: string;
  name: string;
  enabled: boolean;
}) {
  const t = useT();
  const locale = useLocale();
  const [error, setError] = useState<string | null>(null);
  const [needsPassword, setNeedsPassword] = useState(false);
  const [busy, startAction] = useTransition();
  const toast = useToast();

  const remove = () =>
    startAction(async () => {
      const result = await revokeMfa(membershipId);

      // The refusal that asks for a password is not an error to show in red:
      // it is a form (ADR 0034).
      setNeedsPassword(result.needsPassword === true);
      setError(result.needsPassword === true ? null : result.error);

      if (result.error === null) {
        toast({ tone: "removed", message: t("mfa.removed", { name }) });
      }
    });

  if (!enabled) {
    return (
      <Panel
        id="two-factor"
        title={t("settings.twoFactor.label")}
        actions={<Badge tone="neutral" icon="minus">{t("mfa.off")}</Badge>}
      >
        <p className="text-body-sm text-n-700">
          {t("mfa.offBody", { name })}
        </p>
      </Panel>
    );
  }

  return (
    <Panel
      id="two-factor"
      title={t("settings.twoFactor.label")}
      description={t("mfa.desc")}
      actions={<Badge tone="success" icon="check">{t("mfa.on")}</Badge>}
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <p className="max-w-prose text-body-sm text-n-700">
        {t("mfa.body", { name })}
      </p>

      <div className="mt-4">
        <Button
          variant="destructive"
          size="sm"
          disabled={busy}
          onClick={remove}
        >
          {busy ? t("mfa.removing") : t("mfa.remove")}
        </Button>
      </div>

      {needsPassword && (
        <ConfirmPassword
          action={t("mfa.confirmAction", { name })}
          locale={locale}
          onConfirmed={() => {
            setNeedsPassword(false);
            remove();
          }}
        />
      )}
    </Panel>
  );
}

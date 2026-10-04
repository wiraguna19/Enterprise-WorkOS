"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { useToast } from "@/components/ui/Toast";
import { setRuleActive } from "./actions";
import { useT } from "@/i18n/I18nProvider";

/**
 * Switching a rule off, and back on.
 *
 * Off is confirmed; on is not. The asymmetry is the point: turning off the rule
 * that opens approvals stops work being reviewed, silently, everywhere — and
 * the person doing it is usually one click into a list, not reading the rule.
 *
 * Named for what it does to the product, not for the verb it sends: this is a
 * PATCH carrying one field, and calling it "Delete" or "Save" would describe
 * the request instead of the effect.
 */
export function ActiveSwitch({
  id,
  name,
  active,
}: {
  id: string;
  name: string;
  active: boolean;
}) {
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();
  const toast = useToast();
  const t = useT();

  const apply = (next: boolean) =>
    startAction(async () => {
      const result = await setRuleActive(id, next);

      setError(result.error);

      // Left open on failure, so the sentence explaining the refusal has
      // somewhere to sit.
      if (result.error === null) {
        setConfirming(false);

        // The effect is everywhere except this screen: a rule that stopped
        // running stops running for everybody (ADR 0025).
        toast({
          tone: next ? "done" : "removed",
          message: next
            ? t("rule.on.toast", { name })
            : t("rule.off.toast", { name }),
        });
      }
    });

  if (!active) {
    return (
      <span className="inline-flex items-center gap-2">
        {error && (
          <span role="alert" className="text-caption text-s-danger">
            {error}
          </span>
        )}
        <Button variant="affirmative" size="sm" disabled={busy} onClick={() => apply(true)}>
          {busy ? t("rule.starting") : t("hook.switchOn")}
        </Button>
      </span>
    );
  }

  if (!confirming) {
    return (
      <Button variant="destructive" size="sm" onClick={() => setConfirming(true)}>
        {t("hook.switchOff")}
      </Button>
    );
  }

  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      {error && (
        <span role="alert" className="text-caption text-s-danger">
          {error}
        </span>
      )}

      <span className="text-caption text-n-500">
        {t("rule.off.confirm", { name })}
      </span>

      <Button variant="secondary" size="sm" disabled={busy} onClick={() => apply(false)}>
        {busy ? t("rule.stopping") : t("rule.switchItOff")}
      </Button>

      <Button variant="ghost" size="sm" disabled={busy} onClick={() => setConfirming(false)}>
        {t("rule.leaveRunning")}
      </Button>
    </span>
  );
}

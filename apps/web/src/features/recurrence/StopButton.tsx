"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { useToast } from "@/components/ui/Toast";
import { useT } from "@/i18n/I18nProvider";
import { stopRecurrence } from "./actions";

/**
 * Stopping a recurrence, in two clicks.
 *
 * Confirmed, because it cannot be undone from here — there is no endpoint that
 * reactivates one, so "Stop" is the end of this rule and starting again means
 * setting up another. Saying that where the decision is made beats a toast
 * afterwards.
 *
 * It is NOT called Delete. The work this rule already created carries
 * `recurrence_id`, and the row stays so that link still answers "where did this
 * come from". A control that said Delete would promise an erasure the API
 * deliberately refuses to perform.
 */
export function StopButton({ id, schedule }: { id: string; schedule: string }) {
  const t = useT();
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();
  const toast = useToast();

  if (!confirming) {
    return (
      <Button variant="destructive" size="sm" onClick={() => setConfirming(true)}>
        {t("stop.stop")}
      </Button>
    );
  }

  return (
    <span className="inline-flex items-center gap-2">
      {error && (
        <span role="alert" className="text-caption text-s-danger">
          {error}
        </span>
      )}

      <span className="text-caption text-n-500">{t("stop.confirm", { schedule })}</span>

      <Button
        variant="danger"
        size="sm"
        disabled={busy}
        onClick={() =>
          startAction(async () => {
            const result = await stopRecurrence(id);

            setError(result.error);

            if (result.error === null) {
              toast({ tone: "removed", message: t("stop.toast", { schedule }) });
            }

            // Left open on failure. The commonest refusal is one somebody else
            // already stopped, and closing the control would hide the sentence
            // that explains it.
            if (result.error === null) setConfirming(false);
          })
        }
      >
        {busy ? t("stop.stopping") : t("stop.it")}
      </Button>

      <Button variant="ghost" size="sm" disabled={busy} onClick={() => setConfirming(false)}>
        {t("wedit.keepIt")}
      </Button>
    </span>
  );
}

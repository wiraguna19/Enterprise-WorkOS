"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { useT } from "@/i18n/I18nProvider";
import { withdraw } from "./actions";

/**
 * Taking a submission back (docs/08 §7).
 *
 * Two clicks, and the second one says what happens rather than asking "are you
 * sure?" — the same shape as deleting a work item. Withdrawing is not
 * destructive, but it IS visible to the reviewer, and a submission that
 * vanishes from somebody's queue mid-morning deserves a moment's thought from
 * the person removing it.
 *
 * Offered only where the API said so. `permissions.withdraw` has been in every
 * approval payload since Phase 4, read by nothing — the client asked for
 * `decide` and ignored the answer beside it.
 */
export function WithdrawButton({ approvalId }: { approvalId: string }) {
  const t = useT();
  const [armed, setArmed] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const take = () =>
    startTransition(async () => {
      const result = await withdraw(approvalId);

      setError(result.error);

      // Left armed on failure: the commonest refusal is "a reviewer decided
      // while you were reading this", and re-arming would hide the sentence
      // that says so.
      if (result.error === null) setArmed(false);
    });

  return (
    <div className="mt-2 flex flex-wrap items-center gap-2">
      {armed ? (
        <>
          <span className="text-caption text-n-500">
            {t("withdraw.explain")}
          </span>
          <Button variant="danger" size="sm" disabled={pending} onClick={take}>
            {pending ? t("withdraw.withdrawing") : t("withdraw.confirm")}
          </Button>
          <Button variant="ghost" size="sm" disabled={pending} onClick={() => setArmed(false)}>
            {t("withdraw.keep")}
          </Button>
        </>
      ) : (
        <Button
          variant="destructive"
          size="sm"
          onClick={() => {
            setError(null);
            setArmed(true);
          }}
        >
          {t("withdraw.button")}
        </Button>
      )}

      {error !== null && (
        <span role="alert" className="text-caption text-s-danger">
          {error}
        </span>
      )}
    </div>
  );
}

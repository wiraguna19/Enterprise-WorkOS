"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { INPUT } from "@/components/ui/Field";
import { useToast } from "@/components/ui/Toast";
import { cancelLeave, decideLeave } from "./actions";
import { useT } from "@/i18n/I18nProvider";

/** Approve or decline, with an optional note to the person. */
export function LeaveDecision({ id }: { id: string }) {
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const toast = useToast();
  const t = useT();

  const decide = (verdict: "approve" | "reject") =>
    start(async () => {
      const result = await decideLeave(id, verdict, note);

      setError(result.error);

      if (result.error === null) toast({ tone: verdict === "approve" ? "done" : "removed", message: t(verdict === "approve" ? "lv.dec.approved" : "lv.dec.rejected") });
    });

  return (
    <div className="space-y-2">
      {error && <p role="alert" className="text-body-sm text-s-danger">{error}</p>}
      <div className="flex flex-wrap items-center gap-2">
        <input value={note} onChange={(e) => setNote(e.target.value)} placeholder={t("lv.dec.note")} aria-label={t("lv.dec.note")}
          maxLength={1000} className={INPUT.replace("w-full", "w-64 max-w-full")} />
        <Button variant="affirmative" size="sm" disabled={busy} onClick={() => decide("approve")}>{t("lv.dec.approve")}</Button>
        <Button variant="destructive" size="sm" disabled={busy} onClick={() => decide("reject")}>{t("lv.dec.reject")}</Button>
      </div>
    </div>
  );
}

/** Withdraw a request: the person's while it has not started, HR's at any time. */
export function LeaveCancel({ id }: { id: string }) {
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const t = useT();

  return (
    <span className="inline-flex items-center gap-2">
      {error && <span role="alert" className="text-caption text-s-danger">{error}</span>}
      <Button variant="ghost" size="sm" disabled={busy}
        onClick={() => start(async () => setError((await cancelLeave(id)).error))}>
        {t("lv.req.cancel")}
      </Button>
    </span>
  );
}

"use client";

import { useEffect, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { useToast } from "@/components/ui/Toast";
import { quoteLeave, submitLeave } from "./actions";
import { daysLabel } from "./LeaveBits";
import type { LeaveQuote, LeaveType } from "./types";
import { useLocale, useT } from "@/i18n/I18nProvider";

/**
 * Asking for time off. The number of days is the API's answer, asked as the
 * dates change — the browser does not know the holidays or which weekdays
 * this organization works, and a number it guessed would disagree with the
 * one the request is stored with.
 */
export function LeaveRequestForm({ types }: { types: LeaveType[] }) {
  const [typeId, setTypeId] = useState(types[0]?.id ?? "");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [half, setHalf] = useState<"" | "am" | "pm">("");
  const [reason, setReason] = useState("");
  const [quote, setQuote] = useState<LeaveQuote | null>(null);
  const [quoteError, setQuoteError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const toast = useToast();
  const t = useT();
  const locale = useLocale();

  const type = types.find((candidate) => candidate.id === typeId);
  const oneDay = from !== "" && from === to;
  const halfDay = type?.allow_half_day && oneDay && half !== "" ? half : null;

  useEffect(() => {
    if (typeId === "" || from === "" || to === "" || to < from) return;

    let live = true;

    void quoteLeave({ leave_type_id: typeId, starts_on: from, ends_on: to, half_day: halfDay }).then((result) => {
      if (!live) return;
      setQuote(result.quote);
      setQuoteError(result.error);
    });

    return () => {
      live = false;
    };
  }, [typeId, from, to, halfDay]);

  const ready = typeId !== "" && from !== "" && to !== "" && to >= from;
  const shown = ready ? quote : null;
  const over = shown?.uses_quota && shown.available !== null && shown.days > shown.available;

  const submit = () =>
    start(async () => {
      const result = await submitLeave({ leave_type_id: typeId, starts_on: from, ends_on: to, half_day: halfDay, reason });

      setError(result.error);

      if (result.error === null) {
        toast({ message: t("lv.req.sent") });
        setFrom("");
        setTo("");
        setHalf("");
        setReason("");
        setQuote(null);
      }
    });

  return (
    <div className="space-y-3">
      {error && (
        <p role="alert" className="rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <div className="grid gap-3 md:grid-cols-2">
        <Field id="lr-type" label={t("lv.req.type")}>
          <select id="lr-type" value={typeId} onChange={(e) => setTypeId(e.target.value)} className={INPUT}>
            {types.map((candidate) => (
              <option key={candidate.id} value={candidate.id}>{candidate.name}</option>
            ))}
          </select>
        </Field>

        <div className="flex flex-wrap items-end gap-2">
          <Field id="lr-from" label={t("lv.req.from")}>
            <input id="lr-from" type="date" value={from}
              onChange={(e) => { setFrom(e.target.value); if (to === "" || to < e.target.value) setTo(e.target.value); }}
              className={INPUT.replace("w-full", "w-40")} />
          </Field>
          <Field id="lr-to" label={t("lv.req.to")}>
            <input id="lr-to" type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} className={INPUT.replace("w-full", "w-40")} />
          </Field>
          {type?.allow_half_day && oneDay && (
            <Field id="lr-half" label={t("lv.req.half")}>
              <select id="lr-half" value={half} onChange={(e) => setHalf(e.target.value as "" | "am" | "pm")} className={INPUT.replace("w-full", "w-36")}>
                <option value="">{t("lv.req.fullDay")}</option>
                <option value="am">{t("lv.half.am")}</option>
                <option value="pm">{t("lv.half.pm")}</option>
              </select>
            </Field>
          )}
        </div>
      </div>

      <Field id="lr-reason" label={t("lv.req.reason")} hint={t("lv.req.reason.hint")}>
        <textarea id="lr-reason" rows={2} maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)} className={INPUT} />
      </Field>

      {ready && quoteError && <p className="text-body-sm text-s-danger">{quoteError}</p>}

      {shown && (
        <p className={`text-body-sm ${over ? "text-s-danger" : "text-n-700"}`}>
          {daysLabel(shown.days, locale)}
          {shown.uses_quota && shown.available !== null && ` · ${t("lv.req.available", { available: shown.available })}`}
          {shown.document_required && ` · ${t("lv.req.document")}`}
        </p>
      )}

      <Button variant="primary" size="sm" disabled={busy || !ready || !shown || shown.days <= 0 || over === true} onClick={submit}>
        {busy ? t("common.saving") : t("lv.req.submit")}
      </Button>
    </div>
  );
}

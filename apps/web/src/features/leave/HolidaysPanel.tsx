"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button, ButtonLink } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { addHoliday, removeHoliday } from "./settings-actions";
import type { Holiday } from "./types";
import { useLocale, useT } from "@/i18n/I18nProvider";
import { formatDate } from "@/lib/format";

/**
 * The days the organization is closed (ADR 0063). Not a leave type: nobody
 * applies for them, and a request that spans one does not count it.
 */
export function HolidaysPanel({ year, holidays }: { year: number; holidays: Holiday[] }) {
  const [date, setDate] = useState("");
  const [name, setName] = useState("");
  const [kind, setKind] = useState<Holiday["kind"]>("public");
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const toast = useToast();
  const t = useT();
  const locale = useLocale();

  const add = () =>
    start(async () => {
      const result = await addHoliday({ on_date: date, name, kind });

      setError(result.error);

      if (result.error === null) {
        setDate("");
        setName("");
        toast({ message: t("lv.holidays.added") });
      }
    });

  const remove = (id: string) =>
    start(async () => {
      const result = await removeHoliday(id);

      setError(result.error);
    });

  return (
    <Panel
      id="leave-holidays"
      title={t("lv.holidays.title", { year })}
      description={t("lv.holidays.description")}
      actions={
        <div className="flex items-center gap-1">
          <ButtonLink href={`/settings/leave?year=${year - 1}`} variant="ghost" size="sm">{year - 1}</ButtonLink>
          <ButtonLink href={`/settings/leave?year=${year + 1}`} variant="ghost" size="sm">{year + 1}</ButtonLink>
        </div>
      }
    >
      {error && <p role="alert" className="mb-3 text-body-sm text-s-danger">{error}</p>}

      {holidays.length === 0 ? (
        <p className="text-body-sm text-n-500">{t("lv.holidays.empty")}</p>
      ) : (
        <ul className="divide-y divide-n-100">
          {holidays.map((holiday) => (
            <li key={holiday.id} className="flex items-center gap-3 py-2 text-body-sm">
              <span className="w-32 tabular-nums text-n-700">{formatDate(`${holiday.on_date}T00:00:00Z`, "UTC", locale)}</span>
              <span className="min-w-0 flex-1 text-n-900">{holiday.name}</span>
              <Badge tone="neutral">{t(holiday.kind === "public" ? "lv.holidays.public" : "lv.holidays.collective")}</Badge>
              <Button variant="ghost" size="sm" disabled={busy} onClick={() => remove(holiday.id)}>
                {t("lv.remove")}
              </Button>
            </li>
          ))}
        </ul>
      )}

      <div className="mt-4 flex flex-wrap items-end gap-2">
        <Field id="hol-date" label={t("lv.holidays.date")}>
          <input id="hol-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} className={INPUT.replace("w-full", "w-44")} />
        </Field>
        <Field id="hol-name" label={t("lv.holidays.name")}>
          <input id="hol-name" value={name} onChange={(e) => setName(e.target.value)} className={INPUT.replace("w-full", "w-64")} />
        </Field>
        <Field id="hol-kind" label={t("lv.holidays.kind")}>
          <select id="hol-kind" value={kind} onChange={(e) => setKind(e.target.value as Holiday["kind"])} className={INPUT.replace("w-full", "w-44")}>
            <option value="public">{t("lv.holidays.public")}</option>
            <option value="collective">{t("lv.holidays.collective")}</option>
          </select>
        </Field>
        <Button variant="primary" size="sm" disabled={busy || date === "" || name.trim() === ""} onClick={add}>
          {t("lv.holidays.add")}
        </Button>
      </div>
    </Panel>
  );
}

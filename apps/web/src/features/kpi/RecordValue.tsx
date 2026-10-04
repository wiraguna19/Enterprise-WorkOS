"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { useT } from "@/i18n/I18nProvider";
import { recordKpiValue } from "./actions";

/**
 * Entering the value of a manual KPI for one period. Choosing a period that
 * already has a value corrects it; the API keeps one value per period.
 */
export function RecordValue({
  kpiId,
  periods,
}: {
  kpiId: string;
  /** Newest first: [period_start, label]. */
  periods: Array<[string, string]>;
}) {
  const t = useT();
  const router = useRouter();
  const id = useId();
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);
  const [period, setPeriod] = useState(periods[0]?.[0] ?? "");
  const [value, setValue] = useState("");
  const [note, setNote] = useState("");

  return (
    <form
      className="space-y-3"
      onSubmit={(event) => {
        event.preventDefault();
        startTransition(async () => {
          const result = await recordKpiValue(kpiId, { period_start: period, value: Number(value), note: note.trim() });

          setError(result.error);

          if (result.error === null) {
            setValue("");
            setNote("");
            router.refresh();
          }
        });
      }}
    >
      {error && (
        <p role="alert" className="text-body-sm text-s-danger">
          {error}
        </p>
      )}
      <div className="grid gap-3 sm:grid-cols-3">
        <Field id={`${id}-period`} label={t("kpi.record.period")}>
          <select id={`${id}-period`} className={INPUT} value={period} onChange={(event) => setPeriod(event.target.value)}>
            {periods.map(([start, label]) => (
              <option key={start} value={start}>
                {label}
              </option>
            ))}
          </select>
        </Field>
        <Field id={`${id}-value`} label={t("kpi.record.value")}>
          <input
            id={`${id}-value`}
            className={INPUT}
            type="number"
            step="any"
            required
            value={value}
            onChange={(event) => setValue(event.target.value)}
          />
        </Field>
        <Field id={`${id}-note`} label={t("kpi.record.note")}>
          <input id={`${id}-note`} className={INPUT} maxLength={500} value={note} onChange={(event) => setNote(event.target.value)} />
        </Field>
      </div>
      <Button type="submit" variant="affirmative" size="sm" disabled={pending || value === ""}>
        {pending ? t("kpi.record.saving") : t("kpi.record.save")}
      </Button>
    </form>
  );
}

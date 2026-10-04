"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { useT } from "@/i18n/I18nProvider";
import type { MessageKey } from "@/i18n/messages/en";
import { createKpi, updateKpi } from "./actions";
import type { Kpi, KpiVocabulary } from "./types";

/**
 * Defining a KPI, or correcting one (ADR 0062).
 *
 * Everything that would change what the history MEANS — the subject, the
 * source, the period — is chosen once, when the KPI is made. The edit form
 * shows them as text. A computed KPI's unit and direction come from what it
 * measures, so the form does not ask for them.
 */
export function KpiForm({ vocabulary, existing }: { vocabulary?: KpiVocabulary; existing?: Kpi }) {
  const t = useT();
  const router = useRouter();
  const id = useId();
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);

  const subjects = vocabulary?.subjects ?? [];
  const [subject, setSubject] = useState(subjects.length > 0 ? `${subjects[0].type}:${subjects[0].id}` : "");
  const [source, setSource] = useState(existing?.source ?? "manual");
  const [period, setPeriod] = useState<string>(existing?.period ?? "month");
  const [name, setName] = useState(existing?.name ?? "");
  const [description, setDescription] = useState(existing?.description ?? "");
  const [unit, setUnit] = useState(existing?.source === "manual" ? existing.unit : "");
  const [direction, setDirection] = useState<"higher" | "lower">(existing?.direction ?? "higher");
  const [target, setTarget] = useState(existing ? String(existing.target) : "");

  const manual = source === "manual";

  function submit(event: React.FormEvent) {
    event.preventDefault();

    startTransition(async () => {
      let result;

      if (existing) {
        result = await updateKpi(existing.id, {
          name: name.trim(),
          description: description.trim(),
          target: Number(target),
          ...(manual ? { unit: unit.trim(), direction } : {}),
        });
      } else {
        const [subjectType, subjectId] = subject.split(":");

        result = await createKpi({
          name: name.trim(),
          description: description.trim(),
          subject_type: subjectType,
          subject_id: subjectId,
          source,
          unit: manual ? unit.trim() : "",
          direction,
          target: Number(target),
          period,
        });
      }

      setError(result.error);

      if (result.error === null && result.id) {
        router.push(`/kpis/${result.id}`);
        router.refresh();
      }
    });
  }

  return (
    <form onSubmit={submit} className="max-w-2xl space-y-4">
      {error && (
        <p role="alert" className="rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      {!existing && (
        <div className="grid gap-4 sm:grid-cols-2">
          <Field id={`${id}-subject`} label={t("kpi.form.subject")}>
            <select id={`${id}-subject`} className={INPUT} value={subject} onChange={(event) => setSubject(event.target.value)}>
              {(["department", "team", "project"] as const).map((type) => {
                const options = subjects.filter((option) => option.type === type);

                return options.length === 0 ? null : (
                  <optgroup key={type} label={t(`kpi.form.group.${type}` as MessageKey)}>
                    {options.map((option) => (
                      <option key={option.id} value={`${option.type}:${option.id}`}>
                        {option.name}
                      </option>
                    ))}
                  </optgroup>
                );
              })}
            </select>
          </Field>

          <Field id={`${id}-period`} label={t("kpi.form.period")}>
            <select id={`${id}-period`} className={INPUT} value={period} onChange={(event) => setPeriod(event.target.value)}>
              {(vocabulary?.periods ?? []).map((kind) => (
                <option key={kind} value={kind}>
                  {t(`kpi.periodName.${kind}` as MessageKey)}
                </option>
              ))}
            </select>
          </Field>

          <Field id={`${id}-source`} label={t("kpi.form.source")} hint={t(`kpi.sourceHint.${source}` as MessageKey)} className="sm:col-span-2">
            <select id={`${id}-source`} className={INPUT} value={source} onChange={(event) => setSource(event.target.value)}>
              {(vocabulary?.sources ?? []).map((option) => (
                <option key={option.key} value={option.key}>
                  {t(`kpi.source.${option.key}` as MessageKey)}
                </option>
              ))}
            </select>
          </Field>
        </div>
      )}

      <Field id={`${id}-name`} label={t("kpi.form.name")}>
        <input id={`${id}-name`} className={INPUT} value={name} maxLength={120} required onChange={(event) => setName(event.target.value)} />
      </Field>

      <Field id={`${id}-description`} label={t("kpi.form.description")} hint={t("kpi.form.descriptionHint")}>
        <textarea
          id={`${id}-description`}
          className={INPUT}
          rows={3}
          maxLength={1000}
          value={description}
          onChange={(event) => setDescription(event.target.value)}
        />
      </Field>

      <div className="grid gap-4 sm:grid-cols-3">
        <Field id={`${id}-target`} label={t("kpi.form.target")}>
          <input
            id={`${id}-target`}
            className={INPUT}
            type="number"
            step="any"
            required
            value={target}
            onChange={(event) => setTarget(event.target.value)}
          />
        </Field>

        {manual && (
          <>
            <Field id={`${id}-unit`} label={t("kpi.form.unit")} hint={t("kpi.form.unitHint")}>
              <input id={`${id}-unit`} className={INPUT} maxLength={20} value={unit} onChange={(event) => setUnit(event.target.value)} />
            </Field>

            <Field id={`${id}-direction`} label={t("kpi.form.direction")}>
              <select
                id={`${id}-direction`}
                className={INPUT}
                value={direction}
                onChange={(event) => setDirection(event.target.value === "lower" ? "lower" : "higher")}
              >
                <option value="higher">{t("kpi.direction.higher")}</option>
                <option value="lower">{t("kpi.direction.lower")}</option>
              </select>
            </Field>
          </>
        )}
      </div>

      <Button type="submit" variant="primary" disabled={pending || name.trim() === "" || target === "" || (!existing && subject === "")}>
        {existing
          ? pending ? t("kpi.form.saving") : t("kpi.form.save")
          : pending ? t("kpi.form.creating") : t("kpi.form.create")}
      </Button>
    </form>
  );
}

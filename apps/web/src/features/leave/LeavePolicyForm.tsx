"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { applyLeavePreset, saveLeavePolicy } from "./settings-actions";
import { JOB_LEVELS, type LeavePolicy } from "./types";
import { useLocale, useT } from "@/i18n/I18nProvider";
import { INTL_TAG } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";

/** Nothing configured yet: start from a preset, or from nothing. */
export function LeavePresetStart({ presets }: { presets: string[] }) {
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const t = useT();

  return (
    <Panel id="leave-start" title={t("lv.start.title")} description={t("lv.start.description")}>
      {error && <p role="alert" className="mb-3 text-body-sm text-s-danger">{error}</p>}

      <div className="flex flex-wrap gap-2">
        {presets.map((preset) => (
          <Button
            key={preset}
            variant="primary"
            size="sm"
            disabled={busy}
            onClick={() =>
              start(async () => {
                const result = await applyLeavePreset(preset);
                setError(result.error);
              })
            }
          >
            {t(`lv.preset.${preset}` as MessageKey)}
          </Button>
        ))}
      </div>
      <p className="mt-2 max-w-prose text-caption text-n-500">{t("lv.start.note")}</p>
    </Panel>
  );
}

type Draft = Omit<LeavePolicy, "preset" | "updated_at">;

/**
 * The organization's leave rules in one form (ADR 0063).
 *
 * Every number is the company's own; a preset only filled them in. Saved as a
 * whole, like the other policy forms, so what is on the screen is what is
 * stored.
 */
export function LeavePolicyForm({ policy }: { policy: LeavePolicy }) {
  const [draft, setDraft] = useState<Draft>({
    period: policy.period,
    accrual: policy.accrual,
    base_days: policy.base_days,
    probation_months: policy.probation_months,
    carry_over_max_days: policy.carry_over_max_days,
    carry_over_until_month: policy.carry_over_until_month,
    approval: policy.approval,
    working_days: policy.working_days,
    tenure_bonus: policy.tenure_bonus,
    level_bonus: policy.level_bonus,
  });
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const toast = useToast();
  const t = useT();
  const locale = useLocale();

  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((current) => ({ ...current, [key]: value }));

  const weekday = (day: number) =>
    new Intl.DateTimeFormat(INTL_TAG[locale], { weekday: "short", timeZone: "UTC" }).format(
      // 2024-01-01 was a Monday, so day 1..7 lands on Monday..Sunday.
      new Date(Date.UTC(2024, 0, day)),
    );

  const month = (value: number) =>
    new Intl.DateTimeFormat(INTL_TAG[locale], { month: "long", timeZone: "UTC" }).format(new Date(Date.UTC(2024, value - 1, 1)));

  const save = () =>
    start(async () => {
      const result = await saveLeavePolicy(draft);

      setError(result.error);

      if (result.error === null) toast({ message: t("lv.policy.saved") });
    });

  const SELECT = INPUT.replace("w-full", "w-64");
  const NUMBER = INPUT.replace("w-full", "w-28");

  return (
    <Panel id="leave-policy" title={t("lv.policy.title")} description={t("lv.policy.description")}>
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <div className="grid gap-4 md:grid-cols-2">
        <Field id="lv-period" label={t("lv.period")} hint={t("lv.period.hint")}>
          <select id="lv-period" value={draft.period} onChange={(e) => set("period", e.target.value as Draft["period"])} className={SELECT}>
            <option value="calendar_year">{t("lv.period.calendar_year")}</option>
            <option value="hire_anniversary">{t("lv.period.hire_anniversary")}</option>
          </select>
        </Field>

        <Field id="lv-accrual" label={t("lv.accrual")} hint={t("lv.accrual.hint")}>
          <select id="lv-accrual" value={draft.accrual} onChange={(e) => set("accrual", e.target.value as Draft["accrual"])} className={SELECT}>
            <option value="upfront">{t("lv.accrual.upfront")}</option>
            <option value="monthly">{t("lv.accrual.monthly")}</option>
            <option value="monthly_first_year">{t("lv.accrual.monthly_first_year")}</option>
          </select>
        </Field>

        <Field id="lv-base" label={t("lv.base")} hint={t("lv.base.hint")}>
          <input id="lv-base" type="number" min={0} max={365} step={0.5} value={draft.base_days}
            onChange={(e) => set("base_days", Number(e.target.value))} className={NUMBER} />
        </Field>

        <Field id="lv-probation" label={t("lv.probation")} hint={t("lv.probation.hint")}>
          <input id="lv-probation" type="number" min={0} max={24} value={draft.probation_months}
            onChange={(e) => set("probation_months", Number(e.target.value))} className={NUMBER} />
        </Field>

        <Field id="lv-carry" label={t("lv.carry")} hint={t("lv.carry.hint")}>
          <input id="lv-carry" type="number" min={0} max={365} step={0.5} value={draft.carry_over_max_days}
            onChange={(e) => set("carry_over_max_days", Number(e.target.value))} className={NUMBER} />
        </Field>

        <Field id="lv-carry-until" label={t("lv.carryUntil")} hint={t("lv.carryUntil.hint")}>
          <select id="lv-carry-until" value={draft.carry_over_until_month ?? ""}
            onChange={(e) => set("carry_over_until_month", e.target.value === "" ? null : Number(e.target.value))} className={SELECT}>
            <option value="">{t("lv.carryUntil.none")}</option>
            {Array.from({ length: 12 }, (_, index) => index + 1).map((value) => (
              <option key={value} value={value}>{month(value)}</option>
            ))}
          </select>
        </Field>

        <Field id="lv-approval" label={t("lv.approval")} hint={t("lv.approval.hint")}>
          <select id="lv-approval" value={draft.approval} onChange={(e) => set("approval", e.target.value as Draft["approval"])} className={SELECT}>
            <option value="manager">{t("lv.approval.manager")}</option>
            <option value="hr">{t("lv.approval.hr")}</option>
            <option value="manager_then_hr">{t("lv.approval.manager_then_hr")}</option>
          </select>
        </Field>

        <fieldset className="space-y-1">
          <legend className="text-body-sm font-medium text-n-900">{t("lv.workingDays")}</legend>
          <div className="flex flex-wrap gap-3">
            {[1, 2, 3, 4, 5, 6, 7].map((day) => (
              <label key={day} className="flex items-center gap-1.5 text-body-sm text-n-700">
                <input
                  type="checkbox"
                  checked={draft.working_days.includes(day)}
                  onChange={(e) =>
                    set(
                      "working_days",
                      e.target.checked
                        ? [...draft.working_days, day].sort()
                        : draft.working_days.filter((value) => value !== day),
                    )
                  }
                />
                {weekday(day)}
              </label>
            ))}
          </div>
          <p className="text-caption text-n-500">{t("lv.workingDays.hint")}</p>
        </fieldset>
      </div>

      <div className="mt-6 grid gap-6 md:grid-cols-2">
        <fieldset className="space-y-2">
          <legend className="text-body-sm font-medium text-n-900">{t("lv.tenure")}</legend>
          <p className="text-caption text-n-500">{t("lv.tenure.hint")}</p>

          {draft.tenure_bonus.map((band, index) => (
            <div key={index} className="flex items-center gap-2 text-body-sm text-n-700">
              <input type="number" min={1} max={60} aria-label={t("lv.tenure.years")} value={band.years}
                onChange={(e) => set("tenure_bonus", draft.tenure_bonus.map((b, i) => (i === index ? { ...b, years: Number(e.target.value) } : b)))}
                className={INPUT.replace("w-full", "w-20")} />
              <span>{t("lv.tenure.yearsPlus")}</span>
              <input type="number" min={0} max={365} step={0.5} aria-label={t("lv.tenure.days")} value={band.days}
                onChange={(e) => set("tenure_bonus", draft.tenure_bonus.map((b, i) => (i === index ? { ...b, days: Number(e.target.value) } : b)))}
                className={INPUT.replace("w-full", "w-20")} />
              <span>{t("lv.tenure.daysExtra")}</span>
              <Button variant="ghost" size="sm" onClick={() => set("tenure_bonus", draft.tenure_bonus.filter((_, i) => i !== index))}>
                {t("lv.remove")}
              </Button>
            </div>
          ))}

          <Button variant="secondary" size="sm"
            onClick={() => set("tenure_bonus", [...draft.tenure_bonus, { years: (draft.tenure_bonus.at(-1)?.years ?? 0) + 2, days: 1 }])}>
            {t("lv.tenure.add")}
          </Button>
        </fieldset>

        <fieldset className="space-y-2">
          <legend className="text-body-sm font-medium text-n-900">{t("lv.level")}</legend>
          <p className="text-caption text-n-500">{t("lv.level.hint")}</p>

          {JOB_LEVELS.map((level) => (
            <div key={level} className="flex items-center gap-2 text-body-sm text-n-700">
              <span className="w-28">{t(`level.${level}` as MessageKey)}</span>
              <input type="number" min={0} max={365} step={0.5} aria-label={t(`level.${level}` as MessageKey)}
                value={draft.level_bonus[level] ?? 0}
                onChange={(e) => set("level_bonus", { ...draft.level_bonus, [level]: Number(e.target.value) })}
                className={INPUT.replace("w-full", "w-20")} />
              <span>{t("lv.tenure.daysExtra")}</span>
            </div>
          ))}
        </fieldset>
      </div>

      <div className="mt-6">
        <Button variant="primary" size="sm" disabled={busy || draft.working_days.length === 0} onClick={save}>
          {busy ? t("common.saving") : t("common.save")}
        </Button>
      </div>
    </Panel>
  );
}

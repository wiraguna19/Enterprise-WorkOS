"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { updateEmployment } from "./employment-actions";
import type { PersonDetail } from "./types";
import { JOB_LEVELS } from "@/features/leave/types";
import { useT } from "@/i18n/I18nProvider";
import type { MessageKey } from "@/i18n/messages/en";

/**
 * The employment facts leave and capacity are computed from (ADR 0063):
 * hire date (tenure), level, title, contract and weekly hours. Shown only to
 * somebody the API lets change them, which is never the person themselves.
 */
export function EmploymentEditor({ person }: { person: PersonDetail }) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState({
    job_title: person.job_title ?? "",
    job_level: person.job_level,
    hired_at: person.hired_at?.slice(0, 10) ?? null,
    employment_type: person.employment_type ?? "full_time",
    weekly_capacity_hours: person.weekly_capacity_hours ? parseFloat(person.weekly_capacity_hours) : 40,
  });
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const toast = useToast();
  const t = useT();

  const save = () =>
    start(async () => {
      const result = await updateEmployment(person.id, draft);

      setError(result.error);

      if (result.error === null) {
        toast({ message: t("emp.saved") });
        setOpen(false);
      }
    });

  return (
    <Panel
      id="employment-edit"
      title={t("emp.title")}
      description={t("emp.description")}
      actions={
        <Button variant="secondary" size="sm" onClick={() => setOpen(!open)}>
          {open ? t("common.cancel") : t("lv.edit")}
        </Button>
      }
    >
      {open && (
        <div className="space-y-3">
          {error && <p role="alert" className="text-body-sm text-s-danger">{error}</p>}

          <div className="grid gap-3 md:grid-cols-2">
            <Field id="emp-title" label={t("emp.jobTitle")}>
              <input id="emp-title" value={draft.job_title} onChange={(e) => setDraft({ ...draft, job_title: e.target.value })} className={INPUT} />
            </Field>
            <Field id="emp-level" label={t("emp.level")} hint={t("emp.level.hint")}>
              <select id="emp-level" value={draft.job_level ?? ""} onChange={(e) => setDraft({ ...draft, job_level: e.target.value || null })} className={INPUT}>
                <option value="">{t("emp.level.none")}</option>
                {JOB_LEVELS.map((level) => (
                  <option key={level} value={level}>{t(`level.${level}` as MessageKey)}</option>
                ))}
              </select>
            </Field>
            <Field id="emp-hired" label={t("emp.hired")} hint={t("emp.hired.hint")}>
              <input id="emp-hired" type="date" value={draft.hired_at ?? ""} onChange={(e) => setDraft({ ...draft, hired_at: e.target.value || null })} className={INPUT} />
            </Field>
            <Field id="emp-type" label={t("emp.type")}>
              <select id="emp-type" value={draft.employment_type} onChange={(e) => setDraft({ ...draft, employment_type: e.target.value })} className={INPUT}>
                {["full_time", "part_time", "contract", "intern"].map((type) => (
                  <option key={type} value={type}>{t(`emp.type.${type}` as MessageKey)}</option>
                ))}
              </select>
            </Field>
            <Field id="emp-hours" label={t("emp.hours")}>
              <input id="emp-hours" type="number" min={1} max={168} value={draft.weekly_capacity_hours}
                onChange={(e) => setDraft({ ...draft, weekly_capacity_hours: Number(e.target.value) })} className={INPUT.replace("w-full", "w-28")} />
            </Field>
          </div>

          <Button variant="primary" size="sm" disabled={busy} onClick={save}>
            {busy ? t("common.saving") : t("common.save")}
          </Button>
        </div>
      )}
    </Panel>
  );
}

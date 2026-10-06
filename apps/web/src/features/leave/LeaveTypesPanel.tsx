"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { createLeaveType, updateLeaveType, type LeaveTypeInput } from "./settings-actions";
import type { LeaveType } from "./types";
import { useT } from "@/i18n/I18nProvider";

/**
 * What a person may be absent for, each with its own rules (ADR 0063).
 *
 * Switched off rather than deleted: requests made under a type keep saying
 * what they were.
 */
export function LeaveTypesPanel({ types }: { types: LeaveType[] }) {
  const [editing, setEditing] = useState<string | null>(null);
  const t = useT();

  return (
    <Panel
      id="leave-types"
      title={t("lv.types.title")}
      description={t("lv.types.description")}
      actions={
        <Button variant="secondary" size="sm" onClick={() => setEditing(editing === "new" ? null : "new")}>
          {t("lv.types.add")}
        </Button>
      }
    >
      {editing === "new" && <TypeForm onDone={() => setEditing(null)} />}

      <ul className="divide-y divide-n-100">
        {types.map((type) => (
          <li key={type.id} className="py-3">
            <div className="flex flex-wrap items-center gap-2">
              <span className={`font-medium ${type.is_active ? "text-n-900" : "text-n-400 line-through"}`}>{type.name}</span>
              <code className="font-mono text-caption text-n-400">{type.key}</code>
              <Badge tone={type.paid ? "success" : "neutral"}>{type.paid ? t("lv.type.paid") : t("lv.type.unpaid")}</Badge>
              {type.uses_quota && <Badge tone="info">{t("lv.type.quota")}</Badge>}
              {type.after_probation && <Badge tone="neutral">{t("lv.type.afterProbation")}</Badge>}
              {type.max_days_per_request !== null && (
                <Badge tone="neutral">
                  {t(type.day_basis === "calendar_days" ? "lv.type.maxCalendar" : "lv.type.maxWorking", { days: type.max_days_per_request })}
                </Badge>
              )}
              {type.attachment_after_days !== null && (
                <Badge tone="neutral">{t("lv.type.attachment", { days: type.attachment_after_days })}</Badge>
              )}
              {type.allow_half_day && <Badge tone="neutral">{t("lv.type.halfDay")}</Badge>}
              {!type.is_active && <Badge tone="warning">{t("lv.type.inactive")}</Badge>}

              <Button className="ml-auto" variant="ghost" size="sm" onClick={() => setEditing(editing === type.id ? null : type.id)}>
                {t("lv.edit")}
              </Button>
            </div>

            {editing === type.id && <TypeForm type={type} onDone={() => setEditing(null)} />}
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function TypeForm({ type, onDone }: { type?: LeaveType; onDone: () => void }) {
  const [key, setKey] = useState("");
  const [draft, setDraft] = useState<LeaveTypeInput>({
    name: type?.name ?? "",
    paid: type?.paid ?? true,
    uses_quota: type?.uses_quota ?? false,
    after_probation: type?.after_probation ?? false,
    day_basis: type?.day_basis ?? "working_days",
    max_days_per_request: type?.max_days_per_request ?? null,
    attachment_after_days: type?.attachment_after_days ?? null,
    allow_half_day: type?.allow_half_day ?? false,
    is_active: type?.is_active ?? true,
  });
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const toast = useToast();
  const t = useT();

  const set = <K extends keyof LeaveTypeInput>(name: K, value: LeaveTypeInput[K]) =>
    setDraft((current) => ({ ...current, [name]: value }));

  const optionalNumber = (value: string) => (value === "" ? null : Number(value));

  const save = () =>
    start(async () => {
      const result = type === undefined ? await createLeaveType({ ...draft, key }) : await updateLeaveType(type.id, draft);

      setError(result.error);

      if (result.error === null) {
        toast({ message: t("lv.types.saved") });
        onDone();
      }
    });

  const check = (name: "paid" | "uses_quota" | "after_probation" | "allow_half_day" | "is_active", label: string) => (
    <label className="flex items-center gap-1.5 text-body-sm text-n-700">
      <input type="checkbox" checked={draft[name]} onChange={(e) => set(name, e.target.checked)} />
      {label}
    </label>
  );

  return (
    <div className="mt-3 space-y-3 rounded-md border border-n-200 bg-n-25 p-3">
      {error && <p role="alert" className="text-body-sm text-s-danger">{error}</p>}

      <div className="grid gap-3 md:grid-cols-2">
        {type === undefined && (
          <Field id="lt-key" label={t("lv.type.key")} hint={t("lv.type.key.hint")}>
            <input id="lt-key" value={key} onChange={(e) => setKey(e.target.value)} placeholder="study_leave" className={INPUT} />
          </Field>
        )}
        <Field id={`lt-name-${type?.id ?? "new"}`} label={t("lv.type.name")}>
          <input id={`lt-name-${type?.id ?? "new"}`} value={draft.name} onChange={(e) => set("name", e.target.value)} className={INPUT} />
        </Field>
        <Field id={`lt-basis-${type?.id ?? "new"}`} label={t("lv.type.basis")}>
          <select id={`lt-basis-${type?.id ?? "new"}`} value={draft.day_basis}
            onChange={(e) => set("day_basis", e.target.value as LeaveTypeInput["day_basis"])} className={INPUT}>
            <option value="working_days">{t("lv.type.basis.working")}</option>
            <option value="calendar_days">{t("lv.type.basis.calendar")}</option>
          </select>
        </Field>
        <Field id={`lt-max-${type?.id ?? "new"}`} label={t("lv.type.max")} hint={t("lv.type.max.hint")}>
          <input id={`lt-max-${type?.id ?? "new"}`} type="number" min={1} max={366} value={draft.max_days_per_request ?? ""}
            onChange={(e) => set("max_days_per_request", optionalNumber(e.target.value))} className={INPUT.replace("w-full", "w-28")} />
        </Field>
        <Field id={`lt-att-${type?.id ?? "new"}`} label={t("lv.type.attachmentAfter")} hint={t("lv.type.attachmentAfter.hint")}>
          <input id={`lt-att-${type?.id ?? "new"}`} type="number" min={0} max={366} value={draft.attachment_after_days ?? ""}
            onChange={(e) => set("attachment_after_days", optionalNumber(e.target.value))} className={INPUT.replace("w-full", "w-28")} />
        </Field>
      </div>

      <div className="flex flex-wrap gap-4">
        {check("paid", t("lv.type.paid"))}
        {check("uses_quota", t("lv.type.quota"))}
        {check("after_probation", t("lv.type.afterProbation"))}
        {check("allow_half_day", t("lv.type.halfDay"))}
        {type !== undefined && check("is_active", t("lv.type.active"))}
      </div>

      <div className="flex gap-2">
        <Button variant="primary" size="sm" disabled={busy || draft.name.trim() === "" || (type === undefined && key === "")} onClick={save}>
          {busy ? t("common.saving") : t("common.save")}
        </Button>
        <Button variant="ghost" size="sm" disabled={busy} onClick={onDone}>
          {t("common.cancel")}
        </Button>
      </div>
    </div>
  );
}

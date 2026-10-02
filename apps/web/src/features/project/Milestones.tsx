"use client";

import { useId, useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { INPUT } from "@/components/ui/Field";
import { useT } from "@/i18n/I18nProvider";
import { Panel } from "@/components/ui/Panel";
import {
  createMilestone,
  deleteMilestone,
  updateMilestone,
  type Milestone,
  type MilestoneChange,
} from "./actions";

// Labels in the dictionaries as `milestone.status.<value>` (ADR 0060).
const STATUSES: Array<Milestone["status"]> = ["open", "at_risk", "completed", "missed"];

const TONE = { open: "neutral", at_risk: "warning", completed: "success", missed: "danger" } as const;

/**
 * A project's milestones, on its overview (docs/08 §2, ADR 0056).
 *
 * On the overview and not in settings, because a milestone is part of what
 * the project IS — the health signal right above this panel is judged by them
 * — and the people who read a project are the people who need its dates.
 * Changing them is in place, for whoever may: a date moves far more often than
 * a project is reconfigured.
 *
 * **Past due is said, not coloured.** An open milestone whose date has gone
 * reads "past due" in words beside the date, because that is what the health
 * signal is counting and the reader should be able to see which one.
 */
export function Milestones({
  projectKey,
  milestones,
  canManage,
  today,
}: {
  projectKey: string;
  milestones: Milestone[];
  canManage: boolean;
  /** The reader's calendar day, YYYY-MM-DD — past due is a question of whose today. */
  today: string;
}) {
  const t = useT();
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();

  const run = (action: () => Promise<{ error: string | null }>) =>
    start(async () => setError((await action()).error));

  return (
    <Panel
      id="milestones"
      title={t("health.milestones.name")}
      description={
        milestones.length === 0
          ? t("ms.descEmpty")
          : t("ms.desc")
      }
      bleed
    >
      {error !== null && (
        <p role="alert" className="mx-4 mt-3 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      {milestones.length > 0 && (
        <ul className="divide-y divide-n-100">
          {milestones.map((milestone) => (
            <MilestoneRow
              key={milestone.id}
              milestone={milestone}
              canManage={canManage}
              busy={busy}
              today={today}
              onChange={(changes) => run(() => updateMilestone(projectKey, milestone.id, changes))}
              onDelete={() => run(() => deleteMilestone(projectKey, milestone.id))}
            />
          ))}
        </ul>
      )}

      {canManage && (
        <NewMilestone
          busy={busy}
          onCreate={(name, dueDate) => run(() => createMilestone(projectKey, { name, due_date: dueDate }))}
        />
      )}
    </Panel>
  );
}

function MilestoneRow({
  milestone,
  canManage,
  busy,
  today,
  onChange,
  onDelete,
}: {
  milestone: Milestone;
  canManage: boolean;
  busy: boolean;
  today: string;
  onChange: (changes: MilestoneChange) => void;
  onDelete: () => void;
}) {
  const t = useT();
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState(milestone.name);
  const [due, setDue] = useState(milestone.due_date ?? "");
  const [confirming, setConfirming] = useState(false);
  const statusId = useId();

  const outstanding = milestone.status === "open" || milestone.status === "at_risk";
  const pastDue = outstanding && milestone.due_date !== null && milestone.due_date < today;

  if (editing) {
    return (
      <li className="flex flex-wrap items-end gap-2 px-4 py-2.5">
        <label className="min-w-48 flex-1 text-caption text-n-700">
          {t("projects.col.name")}
          <input value={name} onChange={(event) => setName(event.target.value)} className={`${INPUT} mt-0.5`} />
        </label>
        <label className="text-caption text-n-700">
          {t("wi.field.due")}
          <input type="date" value={due} onChange={(event) => setDue(event.target.value)} className={`${INPUT} mt-0.5`} />
        </label>
        <Button
          variant="primary"
          size="sm"
          disabled={busy || name.trim() === ""}
          onClick={() => {
            const changes: MilestoneChange = {};

            if (name.trim() !== milestone.name) changes.name = name.trim();
            if (due !== (milestone.due_date ?? "")) changes.due_date = due === "" ? null : due;
            if (Object.keys(changes).length > 0) onChange(changes);

            setEditing(false);
          }}
        >
          {t("common.save")}
        </Button>
        <Button variant="ghost" size="sm" onClick={() => setEditing(false)}>
          {t("common.cancel")}
        </Button>
      </li>
    );
  }

  return (
    <li className="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-2.5">
      <div className="min-w-0 flex-1">
        <p className="truncate font-medium text-n-900">{milestone.name}</p>
        <p className="text-caption text-n-500">
          {milestone.due_date === null ? t("ms.noDate") : t("health.due", { date: milestone.due_date })}
          {pastDue && <span className="font-medium text-s-danger">{t("ms.pastDue")}</span>}
          {milestone.work_count !== null && (
            <>
              {" · "}
              {milestone.work_count === 0
                ? t("ms.noWork")
                : t("ms.openOf", { open: milestone.open_work_count ?? 0, count: milestone.work_count })}
            </>
          )}
        </p>
      </div>

      {canManage ? (
        <>
          <label htmlFor={statusId} className="sr-only">
            {t("ms.statusOf", { name: milestone.name })}
          </label>
          <select
            id={statusId}
            value={milestone.status}
            disabled={busy}
            onChange={(event) => onChange({ status: event.target.value as Milestone["status"] })}
            className={`${INPUT} w-auto`}
          >
            {STATUSES.map((status) => (
              <option key={status} value={status}>
                {t(`milestone.status.${status}`)}
              </option>
            ))}
          </select>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setEditing(true)}
            aria-label={t("ms.edit", { name: milestone.name })}
          >
            {t("common.edit")}
          </Button>
          {confirming ? (
            <span className="flex items-center gap-1.5">
              <span className="text-caption text-n-700">
                {milestone.work_count ? t("ms.itemsStay", { count: milestone.work_count }) : t("ms.removeQ")}
              </span>
              <Button variant="destructive" size="sm" disabled={busy} onClick={onDelete}>
                {t("ms.remove")}
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setConfirming(false)}>
                {t("ms.keep")}
              </Button>
            </span>
          ) : (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setConfirming(true)}
              aria-label={t("ms.removeName", { name: milestone.name })}
            >
              {t("ms.remove")}
            </Button>
          )}
        </>
      ) : (
        <Badge tone={TONE[milestone.status]}>
          {t(`milestone.status.${milestone.status}`)}
        </Badge>
      )}
    </li>
  );
}

function NewMilestone({
  busy,
  onCreate,
}: {
  busy: boolean;
  onCreate: (name: string, dueDate: string | null) => void;
}) {
  const t = useT();
  const [name, setName] = useState("");
  const [due, setDue] = useState("");

  return (
    <form
      aria-label={t("ms.new")}
      className="flex flex-wrap items-end gap-2 border-t border-n-100 px-4 py-3"
      onSubmit={(event) => {
        event.preventDefault();
        onCreate(name.trim(), due === "" ? null : due);
        setName("");
        setDue("");
      }}
    >
      <label className="min-w-48 flex-1 text-caption text-n-700">
        {t("ms.field")}
        <input
          value={name}
          onChange={(event) => setName(event.target.value)}
          placeholder={t("ms.placeholder")}
          className={`${INPUT} mt-0.5`}
        />
      </label>
      <label className="text-caption text-n-700">
        {t("wi.field.due")}
        <input type="date" value={due} onChange={(event) => setDue(event.target.value)} className={`${INPUT} mt-0.5`} />
      </label>
      <Button type="submit" variant="affirmative" size="sm" disabled={busy || name.trim().length < 2}>
        {t("ms.add")}
      </Button>
    </form>
  );
}

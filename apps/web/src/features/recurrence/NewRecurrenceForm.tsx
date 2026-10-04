"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { describeFields, type TemplateFields } from "@/features/work-item/templates";
import { priorityName } from "@/i18n/labels";
import { useLocale, useT } from "@/i18n/I18nProvider";
import type { MessageKey } from "@/i18n/messages/en";
import { createRecurrence } from "./actions";
import { describe, MAX_MONTH_DAY, toRrule, WEEKDAYS, type Frequency } from "./schedule";

type Option = { id: string; label: string };

/**
 * Setting up recurring work (docs/03 §4).
 *
 * Two halves, and the order matters: WHEN it happens, then WHAT appears. The
 * schedule is first because it is the thing that makes this different from
 * creating a work item — somebody who wanted a one-off is told so by the shape
 * of the form before they fill any of it in.
 *
 * The composed rule is printed under the picker. What gets stored is what the
 * person can read back; a picker that hides its output is a black box with a
 * friendly face, and the first time it produces the wrong week nobody can see
 * why.
 *
 * The due date is RELATIVE — "due N days after it appears" — because that is
 * what a recurring task means. An absolute date in a template would be the same
 * date forever, which is the API's own reasoning for the field being
 * `due_in_days`.
 */
export function NewRecurrenceForm({
  projects,
  people,
  priorities,
  template,
}: {
  projects: Option[];
  people: Option[];
  /** Served by `GET /work-items/vocabulary`, never written out here. */
  priorities: string[];
  /**
   * The work item template this was opened from (ADR 0047). A starting point,
   * copied in — not a link: editing the template later changes nothing about
   * a recurrence already set up, exactly as it changes nothing about work
   * already created from it.
   */
  template?: { name: string; fields: TemplateFields; projectId?: string };
}) {
  const fields = template?.fields;
  const router = useRouter();

  const t = useT();
  const locale = useLocale();
  const [frequency, setFrequency] = useState<Frequency>("weekly");
  const [interval, setInterval] = useState(1);
  const [weekday, setWeekday] = useState<string>("MO");
  const [monthDay, setMonthDay] = useState(1);
  const [endsAt, setEndsAt] = useState("");

  const [title, setTitle] = useState(fields?.title ?? "");
  // A project's template (ADR 0058) sets up recurring work IN that project.
  const [projectId, setProjectId] = useState(template?.projectId ?? "");
  const [assigneeId, setAssigneeId] = useState("");
  const [priority, setPriority] = useState(fields?.priority ?? "");
  const [dueInDays, setDueInDays] = useState(
    fields?.due_in_days === undefined ? "" : String(fields.due_in_days),
  );

  // What the template fills in that this form has no field for. Carried
  // through to every occurrence rather than dropped, and NAMED below, so the
  // person can see what they are setting up.
  const carried: TemplateFields = {
    type: fields?.type,
    description: fields?.description,
    estimate_hours: fields?.estimate_hours,
    custom_fields: fields?.custom_fields,
  };
  const carriedWords = describeFields(carried, locale);

  const [error, setError] = useState<string | null>(null);
  const [requestId, setRequestId] = useState<string | undefined>(undefined);
  const [submitting, startTransition] = useTransition();

  const frequencyId = useId();
  const intervalId = useId();
  const weekdayId = useId();
  const monthDayId = useId();
  const endsId = useId();
  const titleId = useId();
  const projectFieldId = useId();
  const assigneeId_ = useId();
  const priorityId = useId();
  const dueId = useId();

  const rrule = toRrule({ frequency, interval, weekday, monthDay });

  return (
    <form
      className="max-w-2xl space-y-5"
      onSubmit={(event) => {
        event.preventDefault();

        startTransition(async () => {
          const result = await createRecurrence({
            rrule,
            ends_at: endsAt === "" ? null : new Date(`${endsAt}T17:00:00`).toISOString(),
            template: {
              title,
              project_id: projectId,
              assignee_id: assigneeId,
              priority,
              // Left blank means "no due date", not "due the day it appears".
              // `Number("")` is 0, which would quietly say something else.
              due_in_days: dueInDays === "" ? undefined : Number(dueInDays),
              type: carried.type,
              description: carried.description,
              estimate_hours:
                carried.estimate_hours === undefined ? undefined : Number(carried.estimate_hours),
              custom_fields: carried.custom_fields,
            },
          });

          setError(result.error);
          setRequestId(result.requestId);

          if (result.error === null) router.push("/recurring");
        });
      }}
    >
      <section className="space-y-3">
        <h2 className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
          {t("rf.when")}
        </h2>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field id={frequencyId} label={t("rf.repeats")}>
            <select
              id={frequencyId}
              value={frequency}
              onChange={(event) => setFrequency(event.target.value as Frequency)}
              className={INPUT}
            >
              <option value="daily">{t("rf.daily")}</option>
              <option value="weekly">{t("rf.weekly")}</option>
              <option value="monthly">{t("rf.monthly")}</option>
            </select>
          </Field>

          <Field id={intervalId} label={t("rf.every")} hint={t("rf.everyHint")}>
            <input
              id={intervalId}
              type="number"
              min={1}
              max={52}
              value={interval}
              onChange={(event) => setInterval(Math.max(1, Number(event.target.value)))}
              className={INPUT}
            />
          </Field>
        </div>

        {frequency === "weekly" && (
          <Field id={weekdayId} label={t("rf.on")}>
            <select
              id={weekdayId}
              value={weekday}
              onChange={(event) => setWeekday(event.target.value)}
              className={INPUT}
            >
              {WEEKDAYS.map((day) => (
                <option key={day.value} value={day.value}>
                  {t(`weekday.${day.value}` as MessageKey)}
                </option>
              ))}
            </select>
          </Field>
        )}

        {frequency === "monthly" && (
          <Field
            id={monthDayId}
            label={t("rf.monthDay")}
            hint={t("rf.monthDayHint", { max: MAX_MONTH_DAY })}
          >
            <input
              id={monthDayId}
              type="number"
              min={1}
              max={MAX_MONTH_DAY}
              value={monthDay}
              onChange={(event) => setMonthDay(Math.min(MAX_MONTH_DAY, Math.max(1, Number(event.target.value))))}
              className={INPUT}
            />
          </Field>
        )}

        <Field id={endsId} label={t("rf.stops")} hint={t("rf.stopsHint")}>
          <input
            id={endsId}
            type="date"
            value={endsAt}
            onChange={(event) => setEndsAt(event.target.value)}
            className={INPUT}
          />
        </Field>

        {/* The rule itself, in words and as it will be stored. Both, because
            the sentence is what a person checks and the rule is what the
            system runs — and the day they disagree, only showing one of them
            makes that impossible to see. */}
        <p className="text-caption text-n-500">
          {describe(rrule, locale)} · <span className="font-mono">{rrule}</span>
        </p>
      </section>

      <section className="space-y-3 border-t border-n-100 pt-4">
        <h2 className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
          {t("rf.appears")}
        </h2>

        {template !== undefined && (
          <p role="status" className="text-caption text-n-500">
            {t("wnew.filledFrom.before")} <span className="font-medium text-n-700">{template.name}</span>.
            {carriedWords !== "" && ` ${t("rf.carried", { words: carriedWords })}`}{" "}
            {t("rf.changeBeforeSave")}
          </p>
        )}

        <Field id={titleId} label={t("tpl.titleLabel")}>
          <input
            id={titleId}
            type="text"
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            maxLength={500}
            required
            placeholder={t("rf.titlePlaceholder")}
            className={INPUT}
          />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field id={projectFieldId} label={t("wnew.project")} hint={t("form.optional")}>
            <select
              id={projectFieldId}
              value={projectId}
              onChange={(event) => setProjectId(event.target.value)}
              className={INPUT}
            >
              <option value="">{t("wnew.noProjectOption")}</option>
              {projects.map((project) => (
                <option key={project.id} value={project.id}>
                  {project.label}
                </option>
              ))}
            </select>
          </Field>

          <Field id={assigneeId_} label={t("wi.field.assignee")} hint={t("form.optional")}>
            <select
              id={assigneeId_}
              value={assigneeId}
              onChange={(event) => setAssigneeId(event.target.value)}
              className={INPUT}
            >
              <option value="">{t("rf.nobody")}</option>
              {people.map((person) => (
                <option key={person.id} value={person.id}>
                  {person.label}
                </option>
              ))}
            </select>
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field id={priorityId} label={t("form.priority")}>
            <select
              id={priorityId}
              value={priority}
              onChange={(event) => setPriority(event.target.value)}
              className={INPUT}
            >
              <option value="">{t("form.default")}</option>
              {priorities.map((value) => (
                <option key={value} value={value}>
                  {priorityName(value, t)}
                </option>
              ))}
            </select>
          </Field>

          <Field
            id={dueId}
            label={t("rf.dueAfter")}
            hint={t("rf.dueAfterHint")}
          >
            <input
              id={dueId}
              type="number"
              min={0}
              max={365}
              value={dueInDays}
              onChange={(event) => setDueInDays(event.target.value)}
              placeholder="3"
              className={INPUT}
            />
          </Field>
        </div>
      </section>

      {error && (
        <p role="alert" className="text-caption text-s-danger">
          {error}
          {requestId && <span className="ml-2 font-mono text-n-500">{requestId}</span>}
        </p>
      )}

      <Button type="submit" variant="primary" disabled={submitting}>
        {submitting ? t("rf.settingUp") : t("rf.submit")}
      </Button>
    </form>
  );
}

"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
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
}: {
  projects: Option[];
  people: Option[];
}) {
  const router = useRouter();

  const [frequency, setFrequency] = useState<Frequency>("weekly");
  const [interval, setInterval] = useState(1);
  const [weekday, setWeekday] = useState<string>("MO");
  const [monthDay, setMonthDay] = useState(1);
  const [endsAt, setEndsAt] = useState("");

  const [title, setTitle] = useState("");
  const [projectId, setProjectId] = useState("");
  const [assigneeId, setAssigneeId] = useState("");
  const [priority, setPriority] = useState("");
  const [dueInDays, setDueInDays] = useState("");

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
          When
        </h2>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field id={frequencyId} label="Repeats">
            <select
              id={frequencyId}
              value={frequency}
              onChange={(event) => setFrequency(event.target.value as Frequency)}
              className={INPUT}
            >
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
          </Field>

          <Field id={intervalId} label="Every" hint="1 means every one of them.">
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
          <Field id={weekdayId} label="On">
            <select
              id={weekdayId}
              value={weekday}
              onChange={(event) => setWeekday(event.target.value)}
              className={INPUT}
            >
              {WEEKDAYS.map((day) => (
                <option key={day.value} value={day.value}>
                  {day.label}
                </option>
              ))}
            </select>
          </Field>
        )}

        {frequency === "monthly" && (
          <Field
            id={monthDayId}
            label="Day of the month"
            hint={`1 to ${MAX_MONTH_DAY}. Later days do not exist in every month, so they are not offered.`}
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

        <Field id={endsId} label="Stops after" hint="Optional. It runs until stopped otherwise.">
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
          {describe(rrule)} · <span className="font-mono">{rrule}</span>
        </p>
      </section>

      <section className="space-y-3 border-t border-n-100 pt-4">
        <h2 className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500">
          What appears
        </h2>

        <Field id={titleId} label="Title">
          <input
            id={titleId}
            type="text"
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            maxLength={500}
            required
            placeholder="Weekly deployment checklist"
            className={INPUT}
          />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field id={projectFieldId} label="Project" hint="Optional.">
            <select
              id={projectFieldId}
              value={projectId}
              onChange={(event) => setProjectId(event.target.value)}
              className={INPUT}
            >
              <option value="">No project</option>
              {projects.map((project) => (
                <option key={project.id} value={project.id}>
                  {project.label}
                </option>
              ))}
            </select>
          </Field>

          <Field id={assigneeId_} label="Assignee" hint="Optional.">
            <select
              id={assigneeId_}
              value={assigneeId}
              onChange={(event) => setAssigneeId(event.target.value)}
              className={INPUT}
            >
              <option value="">Nobody</option>
              {people.map((person) => (
                <option key={person.id} value={person.id}>
                  {person.label}
                </option>
              ))}
            </select>
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field id={priorityId} label="Priority">
            <select
              id={priorityId}
              value={priority}
              onChange={(event) => setPriority(event.target.value)}
              className={INPUT}
            >
              <option value="">Default</option>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
          </Field>

          <Field
            id={dueId}
            label="Due after"
            hint="Days from when it appears. Blank means no due date."
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
        {submitting ? "Setting up…" : "Set up recurring work"}
      </Button>
    </form>
  );
}

"use client";

import { useRouter } from "next/navigation";
import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { deleteWorkItem, updateWorkItem, type WorkItemEdit } from "../actions";

/**
 * Editing a work item (docs/08 §4, docs/03 §8).
 *
 * Three things here are not decoration:
 *
 *   1. **Only changed fields are sent.** The form compares each field against
 *      what it was given and builds the patch from the difference. PATCH is
 *      what makes the activity log's diff meaningful — a save that reports
 *      every field as touched turns the history into noise nobody reads.
 *   2. **The version travels with the edit.** `lock_version` is what the item
 *      was when this page rendered. If somebody else saved in between, the API
 *      answers 409 with both numbers and this form STOPS: it does not retry,
 *      does not merge, and does not offer to force. The other person's edit is
 *      not an obstacle.
 *   3. **Status is not here.** It moves through /transition, which has rules
 *      and side effects a field edit does not (approvals open, rules fire). A
 *      status picker on an edit form would be a second, quieter way to make
 *      the same change with none of that.
 *
 * Deleting lives at the bottom, behind a second click, and says what it
 * actually does: the server soft-deletes, so the record and its history
 * survive — but no screen restores one, and saying "you can undo this" when
 * nothing can would be worse than saying nothing.
 */
export function EditWorkItemForm({
  reference,
  initial,
  lockVersion,
  canDelete,
  timeZone,
}: {
  reference: string;
  initial: {
    title: string;
    description: string;
    priority: string;
    start_date: string | null;
    due_at: string | null;
    estimate_hours: string | null;
  };
  lockVersion: number;
  canDelete: boolean;
  timeZone: string;
}) {
  const router = useRouter();

  const [title, setTitle] = useState(initial.title);
  const [description, setDescription] = useState(initial.description);
  const [priority, setPriority] = useState(initial.priority);
  const [startDate, setStartDate] = useState(dateInput(initial.start_date, timeZone));
  const [dueAt, setDueAt] = useState(dateInput(initial.due_at, timeZone));
  const [estimate, setEstimate] = useState(initial.estimate_hours ?? "");

  const [error, setError] = useState<string | null>(null);
  const [conflict, setConflict] = useState<{ yours: number; current: number } | null>(null);
  const [saving, startTransition] = useTransition();

  const titleId = useId();
  const descriptionId = useId();
  const priorityId = useId();
  const startId = useId();
  const dueId = useId();
  const estimateId = useId();

  const save = () =>
    startTransition(async () => {
      const changes: WorkItemEdit = {};

      if (title !== initial.title) changes.title = title;
      if (description !== initial.description) changes.description = description;
      if (priority !== initial.priority) changes.priority = priority;

      const originalStart = dateInput(initial.start_date, timeZone);
      const originalDue = dateInput(initial.due_at, timeZone);

      // A cleared date is `null`, not "": the column is nullable and the
      // validator takes nullable, but an empty string is neither a date nor an
      // absence to it.
      if (startDate !== originalStart) changes.start_date = startDate === "" ? null : startDate;
      if (dueAt !== originalDue) {
        changes.due_at = dueAt === "" ? null : `${dueAt}T17:00:00`;
      }

      if (estimate !== (initial.estimate_hours ?? "")) {
        changes.estimate_hours = estimate === "" ? null : estimate;
      }

      const result = await updateWorkItem(reference, changes, lockVersion);

      setError(result.error);
      setConflict(result.conflict ?? null);

      if (result.error === null) router.push(`/work/${reference}`);
    });

  return (
    <form
      className="max-w-2xl space-y-4"
      onSubmit={(event) => {
        event.preventDefault();
        save();
      }}
    >
      <Field id={titleId} label="Title">
        <input
          id={titleId}
          type="text"
          value={title}
          onChange={(event) => setTitle(event.target.value)}
          minLength={2}
          maxLength={500}
          required
          className={INPUT}
        />
      </Field>

      <Field id={descriptionId} label="Description">
        <textarea
          id={descriptionId}
          value={description}
          onChange={(event) => setDescription(event.target.value)}
          rows={5}
          maxLength={20000}
          className={`${INPUT} resize-y`}
        />
      </Field>

      <div className="grid gap-4 sm:grid-cols-3">
        <Field id={priorityId} label="Priority">
          <select
            id={priorityId}
            value={priority}
            onChange={(event) => setPriority(event.target.value)}
            className={INPUT}
          >
            {["low", "medium", "high", "urgent"].map((value) => (
              <option key={value} value={value}>
                {value.charAt(0).toUpperCase() + value.slice(1)}
              </option>
            ))}
          </select>
        </Field>

        <Field id={startId} label="Start">
          <input
            id={startId}
            type="date"
            value={startDate}
            onChange={(event) => setStartDate(event.target.value)}
            className={INPUT}
          />
        </Field>

        <Field id={dueId} label="Due">
          <input
            id={dueId}
            type="date"
            value={dueAt}
            min={startDate === "" ? undefined : startDate}
            onChange={(event) => setDueAt(event.target.value)}
            className={INPUT}
          />
        </Field>
      </div>

      <Field id={estimateId} label="Estimate" hint="Hours. Empty clears it.">
        <input
          id={estimateId}
          type="number"
          inputMode="decimal"
          step="0.25"
          min="0"
          max="9999"
          value={estimate}
          onChange={(event) => setEstimate(event.target.value)}
          className={`${INPUT} max-w-32`}
        />
      </Field>

      <div className="flex items-center gap-3 border-t border-n-100 pt-4">
        <Button type="submit" variant="primary" disabled={saving || conflict !== null}>
          {saving ? "Saving…" : "Save changes"}
        </Button>

        <Button type="button" variant="ghost" disabled={saving} onClick={() => router.back()}>
          Cancel
        </Button>
      </div>

      {conflict !== null ? (
        // A conflict is not a validation error and does not get the same
        // sentence. Nothing here offers to force the save: the only honest
        // moves are to look at what changed or to start again from it.
        <div role="alert" className="space-y-2 rounded-sm border border-s-active/40 bg-s-active/5 p-3">
          <p className="text-body-sm text-n-900">
            Somebody else saved this item while you had it open — it is now at version{" "}
            {conflict.current}, and you started from {conflict.yours}. Your changes have not been
            saved, and theirs have not been touched.
          </p>
          <Button size="sm" onClick={() => router.refresh()}>
            Reload their version
          </Button>
        </div>
      ) : (
        error !== null && (
          <p role="alert" className="text-caption text-s-danger">
            {error}
          </p>
        )
      )}

      {canDelete && <DeleteControl reference={reference} disabled={saving} />}
    </form>
  );
}

/**
 * Two clicks, because one is how work disappears by accident — and the second
 * click's label says what happens rather than asking "are you sure?", which is
 * a question nobody has ever answered by reading it.
 */
function DeleteControl({ reference, disabled }: { reference: string; disabled: boolean }) {
  const router = useRouter();
  const [armed, setArmed] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deleting, startTransition] = useTransition();

  const remove = () =>
    startTransition(async () => {
      const result = await deleteWorkItem(reference);

      if (result.error !== null) {
        setError(result.error);
        setArmed(false);

        return;
      }

      // Back to the list, never to the item: it is gone, and the page that
      // would render it is the one place this must not land.
      router.push("/my-work");
    });

  return (
    <div className="border-t border-n-100 pt-4">
      {armed ? (
        <div className="space-y-2">
          <p className="text-caption text-n-500">
            It stops appearing in every list and board. The record and its history are kept, but
            no screen in this product brings one back.
          </p>
          <div className="flex items-center gap-2">
            <Button
              type="button"
              variant="danger"
              size="sm"
              disabled={deleting}
              onClick={remove}
            >
              {deleting ? "Deleting…" : "Delete it"}
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={deleting}
              onClick={() => setArmed(false)}
            >
              Keep it
            </Button>
          </div>
        </div>
      ) : (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          disabled={disabled}
          onClick={() => {
            setError(null);
            setArmed(true);
          }}
          className="text-s-danger"
        >
          Delete this item
        </Button>
      )}

      {error !== null && (
        <p role="alert" className="mt-1 text-caption text-s-danger">
          {error}
        </p>
      )}
    </div>
  );
}

/**
 * A timestamp as the `<input type="date">` value the person is looking at.
 *
 * Sliced in the ITEM's time zone, not the browser's: an item due at 17:00 in
 * Makassar is the 8th, and a naive `toISOString().slice(0, 10)` would show the
 * 8th to some people and the 7th to others, then save that shift back.
 */
function dateInput(value: string | null, timeZone: string): string {
  if (value === null) return "";

  const parts = new Intl.DateTimeFormat("en-CA", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    timeZone,
  }).format(new Date(value));

  return parts;
}

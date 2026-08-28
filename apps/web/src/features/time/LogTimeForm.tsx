"use client";

import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { logTime } from "./actions";

/**
 * Logging time on a work item (docs/08 §4).
 *
 * Defaults to today and to an empty duration. It does NOT default to a number
 * of hours: a prefilled "1" that someone taps past is how a timesheet fills up
 * with hours nobody worked, and a plausible wrong number is worse than a blank
 * one because nothing downstream can tell it apart from a real one.
 *
 * The date is a plain date input rather than a "yesterday / today" toggle,
 * because the case this exists for is catching up on Monday for last Thursday.
 */
export function LogTimeForm({ reference }: { reference: string }) {
  const [hours, setHours] = useState("");
  const [loggedOn, setLoggedOn] = useState(() => new Date().toISOString().slice(0, 10));
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, startTransition] = useTransition();

  const hoursId = useId();
  const dateId = useId();
  const noteId = useId();

  const submit = () =>
    startTransition(async () => {
      const result = await logTime(reference, hours, loggedOn, note);

      // Shown verbatim: the API's message already names the rule that was
      // broken — "Time can only be logged for today or earlier", or the daily
      // ceiling — and a generic failure message would throw that away.
      setError(result.error);

      if (result.error === null) {
        setHours("");
        setNote("");
      }
    });

  return (
    <form
      className="flex flex-wrap items-end gap-2"
      onSubmit={(event) => {
        event.preventDefault();
        submit();
      }}
    >
      <Field id={hoursId} label="Hours" className="w-20">
        <input
          id={hoursId}
          type="number"
          inputMode="decimal"
          step="0.25"
          min="0"
          max="24"
          value={hours}
          onChange={(event) => setHours(event.target.value)}
          placeholder="0"
          className={INPUT}
          required
        />
      </Field>

      <Field id={dateId} label="Date" className="w-36">
        <input
          id={dateId}
          type="date"
          value={loggedOn}
          // The API refuses the future; so does the picker, so the common
          // mistake is not reachable rather than merely rejected.
          max={new Date().toISOString().slice(0, 10)}
          onChange={(event) => setLoggedOn(event.target.value)}
          className={INPUT}
          required
        />
      </Field>

      <Field id={noteId} label="Note" className="min-w-40 flex-1">
        <input
          id={noteId}
          type="text"
          value={note}
          maxLength={300}
          onChange={(event) => setNote(event.target.value)}
          placeholder="Optional"
          className={INPUT}
        />
      </Field>

      <Button type="submit" size="sm" disabled={submitting || hours === ""}>
        {submitting ? "Logging…" : "Log time"}
      </Button>

      {error && (
        <p role="alert" className="w-full text-caption text-s-danger">
          {error}
        </p>
      )}
    </form>
  );
}

const INPUT =
  "w-full rounded-md border border-n-200 bg-n-0 px-2 py-1 text-body-sm text-n-900 placeholder:text-n-400 focus:border-a-500 focus:outline-none focus:ring-2 focus:ring-a-500/30";

function Field({
  id,
  label,
  className = "",
  children,
}: {
  id: string;
  label: string;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div className={className}>
      <label
        htmlFor={id}
        className="mb-0.5 block text-micro font-semibold uppercase tracking-[0.04em] text-n-500"
      >
        {label}
      </label>
      {children}
    </div>
  );
}

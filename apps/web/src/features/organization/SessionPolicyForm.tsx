"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { ConfirmPassword } from "@/components/ui/ConfirmPassword";
import { Field } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { setSessionPolicy } from "./actions";

/**
 * How long a session may live in this organization (ADR 0028).
 *
 * Presets rather than a free number box. The bounds are 1 and 90, and every
 * value between them is legal, but offering 47 as readily as 30 invites a
 * choice nobody can justify — and the number that matters is which end of the
 * range you are near, not the digit. "Custom" is not owed here; the API takes
 * any integer in range for whoever needs one.
 *
 * The consequence is stated BEFORE the button, not after: shortening the window
 * pulls back sessions that already exist, and an administrator who learns that
 * from the people who were signed out has learned it too late.
 */
const CHOICES: Array<{ days: number; label: string; note?: string }> = [
  { days: 1, label: "1 day", note: "Shared machines. Everybody signs in daily." },
  { days: 7, label: "7 days", note: "A working week." },
  { days: 14, label: "14 days" },
  { days: 30, label: "30 days", note: "The product default." },
  { days: 60, label: "60 days" },
  { days: 90, label: "90 days", note: "The longest allowed." },
];

/**
 * The idle window, in minutes, and "never" as a real answer (ADR 0029).
 *
 * Off is the first entry and the default, because switching it on signs out
 * everybody who has stepped away — a thing somebody should choose, not inherit.
 */
const IDLE_CHOICES: Array<{ minutes: number | null; label: string; note?: string }> = [
  { minutes: null, label: "Never", note: "Only the lifetime above ends a session." },
  { minutes: 30, label: "After 30 minutes" },
  { minutes: 60, label: "After 1 hour" },
  { minutes: 480, label: "After 8 hours", note: "A working day." },
  { minutes: 1440, label: "After 24 hours" },
  { minutes: 10080, label: "After 7 days", note: "The longest allowed." },
];

export function SessionPolicyForm({
  current,
  currentIdle,
  editable,
}: {
  current: number;
  currentIdle: number | null;
  /** False for somebody who may read this page but not change it. */
  editable: boolean;
}) {
  const [days, setDays] = useState(current);
  const [idle, setIdle] = useState<number | null>(currentIdle);
  const [error, setError] = useState<string | null>(null);
  const [needsPassword, setNeedsPassword] = useState(false);
  const [busy, startAction] = useTransition();
  const toast = useToast();

  const save = () =>
    startAction(async () => {
      const result = await setSessionPolicy(days, idle);

      setNeedsPassword(result.needsPassword === true);
      setError(result.needsPassword === true ? null : result.error);

      if (result.error === null) {
        toast({
          tone: result.shortened > 0 ? "removed" : "done",
          message:
            result.shortened > 0
              ? `Sessions now last ${days} ${days === 1 ? "day" : "days"}. ${result.shortened} open ${result.shortened === 1 ? "session was" : "sessions were"} shortened.`
              : `Sessions now last ${days} ${days === 1 ? "day" : "days"}.`,
        });
      }
    });

  const changed = days !== current || idle !== currentIdle;
  const shortening = days < current;
  // Tightening the idle window bites on the next request rather than at save
  // time, so it is worth saying out loud before the button is pressed.
  const tightening =
    idle !== currentIdle && idle !== null && (currentIdle === null || idle < currentIdle);

  return (
    <Panel
      id="session-policy"
      title="Session lifetime"
      description="How long somebody stays signed in here before they have to prove who they are again."
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <div className="max-w-md space-y-3">
        <Field
          id="session-lifetime"
          label="Sessions last"
          hint={
            editable
              ? "Applies to the next sign-in. Shortening it also pulls back sessions that are already open."
              : "Changing this needs the organization settings permission."
          }
        >
          <select
            id="session-lifetime"
            value={days}
            disabled={!editable || busy}
            onChange={(event) => setDays(Number(event.target.value))}
            className="w-56 rounded-md border border-n-300 bg-n-0 px-2 py-1.5 text-body-sm text-n-900 focus:border-a-500 focus:outline-none focus:ring-2 focus:ring-a-500/30 disabled:bg-n-50 disabled:text-n-500"
          >
            {CHOICES.map((choice) => (
              <option key={choice.days} value={choice.days}>
                {choice.label}
                {choice.note ? ` — ${choice.note}` : ""}
              </option>
            ))}
          </select>
        </Field>

        <Field
          id="idle-timeout"
          label="Sign out after inactivity"
          hint={
            editable
              ? "Measured from the last request that session made. It takes effect on the next one, including for sessions that are already idle."
              : "Changing this needs the organization settings permission."
          }
        >
          <select
            id="idle-timeout"
            value={idle === null ? "never" : String(idle)}
            disabled={!editable || busy}
            onChange={(event) =>
              setIdle(event.target.value === "never" ? null : Number(event.target.value))
            }
            className="w-56 rounded-md border border-n-300 bg-n-0 px-2 py-1.5 text-body-sm text-n-900 focus:border-a-500 focus:outline-none focus:ring-2 focus:ring-a-500/30 disabled:bg-n-50 disabled:text-n-500"
          >
            {IDLE_CHOICES.map((choice) => (
              <option key={choice.label} value={choice.minutes === null ? "never" : choice.minutes}>
                {choice.label}
                {choice.note ? ` — ${choice.note}` : ""}
              </option>
            ))}
          </select>
        </Field>

        {tightening && (
          <p className="rounded-md border border-s-active/30 bg-s-active/10 px-3 py-2 text-body-sm text-s-active">
            Anybody who has already been away longer than that is signed out on their next
            request — including, if you have been reading this page for a while, you.
          </p>
        )}

        {changed && shortening && (
          <p className="rounded-md border border-s-active/30 bg-s-active/10 px-3 py-2 text-body-sm text-s-active">
            Sessions open right now that would outlive {days} {days === 1 ? "day" : "days"} will be
            cut back to it. Nobody is signed out immediately.
          </p>
        )}

        {editable && (
          <div className="flex items-center gap-2">
            <Button
              variant="primary"
              size="sm"
              disabled={!changed || busy}
              onClick={save}
            >
              {busy ? "Saving…" : "Save"}
            </Button>

            {changed && (
              <Button
                variant="ghost"
                size="sm"
                disabled={busy}
                onClick={() => {
                  setDays(current);
                  setIdle(currentIdle);
                }}
              >
                Cancel
              </Button>
            )}
          </div>
        )}

        {needsPassword && (
          <ConfirmPassword
            action="change how long sessions last here"
            onConfirmed={() => {
              setNeedsPassword(false);
              save();
            }}
          />
        )}
      </div>
    </Panel>
  );
}

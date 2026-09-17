"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { setSessionLifetime } from "./actions";

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

export function SessionPolicyForm({
  current,
  editable,
}: {
  current: number;
  /** False for somebody who may read this page but not change it. */
  editable: boolean;
}) {
  const [days, setDays] = useState(current);
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();
  const toast = useToast();

  const changed = days !== current;
  const shortening = days < current;

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
              onClick={() =>
                startAction(async () => {
                  const result = await setSessionLifetime(days);

                  setError(result.error);

                  if (result.error === null) {
                    toast({
                      tone: result.shortened > 0 ? "removed" : "done",
                      message:
                        result.shortened > 0
                          ? `Sessions now last ${days} ${days === 1 ? "day" : "days"}. ${result.shortened} open ${result.shortened === 1 ? "session was" : "sessions were"} shortened.`
                          : `Sessions now last ${days} ${days === 1 ? "day" : "days"}.`,
                    });
                  }
                })
              }
            >
              {busy ? "Saving…" : "Save"}
            </Button>

            {changed && (
              <Button variant="ghost" size="sm" disabled={busy} onClick={() => setDays(current)}>
                Cancel
              </Button>
            )}
          </div>
        )}
      </div>
    </Panel>
  );
}

"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { stopRecurrence } from "./actions";

/**
 * Stopping a recurrence, in two clicks.
 *
 * Confirmed, because it cannot be undone from here — there is no endpoint that
 * reactivates one, so "Stop" is the end of this rule and starting again means
 * setting up another. Saying that where the decision is made beats a toast
 * afterwards.
 *
 * It is NOT called Delete. The work this rule already created carries
 * `recurrence_id`, and the row stays so that link still answers "where did this
 * come from". A control that said Delete would promise an erasure the API
 * deliberately refuses to perform.
 */
export function StopButton({ id, schedule }: { id: string; schedule: string }) {
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();

  if (!confirming) {
    return (
      <Button variant="destructive" size="sm" onClick={() => setConfirming(true)}>
        Stop
      </Button>
    );
  }

  return (
    <span className="inline-flex items-center gap-2">
      {error && (
        <span role="alert" className="text-caption text-s-danger">
          {error}
        </span>
      )}

      <span className="text-caption text-n-500">Stop “{schedule}”? No more work appears.</span>

      <Button
        variant="danger"
        size="sm"
        disabled={busy}
        onClick={() =>
          startAction(async () => {
            const result = await stopRecurrence(id);

            setError(result.error);

            // Left open on failure. The commonest refusal is one somebody else
            // already stopped, and closing the control would hide the sentence
            // that explains it.
            if (result.error === null) setConfirming(false);
          })
        }
      >
        {busy ? "Stopping…" : "Stop it"}
      </Button>

      <Button variant="ghost" size="sm" disabled={busy} onClick={() => setConfirming(false)}>
        Keep it
      </Button>
    </span>
  );
}

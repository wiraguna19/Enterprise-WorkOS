"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { setRuleActive } from "./actions";

/**
 * Switching a rule off, and back on.
 *
 * Off is confirmed; on is not. The asymmetry is the point: turning off the rule
 * that opens approvals stops work being reviewed, silently, everywhere — and
 * the person doing it is usually one click into a list, not reading the rule.
 *
 * Named for what it does to the product, not for the verb it sends: this is a
 * PATCH carrying one field, and calling it "Delete" or "Save" would describe
 * the request instead of the effect.
 */
export function ActiveSwitch({
  id,
  name,
  active,
}: {
  id: string;
  name: string;
  active: boolean;
}) {
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();

  const apply = (next: boolean) =>
    startAction(async () => {
      const result = await setRuleActive(id, next);

      setError(result.error);

      // Left open on failure, so the sentence explaining the refusal has
      // somewhere to sit.
      if (result.error === null) setConfirming(false);
    });

  if (!active) {
    return (
      <span className="inline-flex items-center gap-2">
        {error && (
          <span role="alert" className="text-caption text-s-danger">
            {error}
          </span>
        )}
        <Button variant="secondary" size="sm" disabled={busy} onClick={() => apply(true)}>
          {busy ? "Starting…" : "Switch on"}
        </Button>
      </span>
    );
  }

  if (!confirming) {
    return (
      <Button variant="ghost" size="sm" onClick={() => setConfirming(true)}>
        Switch off
      </Button>
    );
  }

  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      {error && (
        <span role="alert" className="text-caption text-s-danger">
          {error}
        </span>
      )}

      <span className="text-caption text-n-500">
        Stop “{name}” running? Nothing it does will happen until it is switched back on.
      </span>

      <Button variant="secondary" size="sm" disabled={busy} onClick={() => apply(false)}>
        {busy ? "Stopping…" : "Switch it off"}
      </Button>

      <Button variant="ghost" size="sm" disabled={busy} onClick={() => setConfirming(false)}>
        Leave it running
      </Button>
    </span>
  );
}

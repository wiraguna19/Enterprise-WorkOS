"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { revokeMfa } from "./roles";

/**
 * Unlocking somebody who has lost their phone (ADR 0031).
 *
 * Offered only to whoever may take this person's access away, and only when
 * there is something to take off — the API sends `mfa_enabled` to nobody else,
 * so this section does not exist for a reader who could not act on it.
 *
 * It says what it costs before it is pressed. Removing the factor does not sign
 * the person out and does not touch their password; it means their password
 * alone gets them in again, until they set a new factor up. An administrator
 * who thinks this is harmless will use it on a phone call from somebody they
 * have not identified, which is the actual attack this control is exposed to.
 */
export function RevokeMfa({
  membershipId,
  name,
  enabled,
}: {
  membershipId: string;
  name: string;
  enabled: boolean;
}) {
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();
  const toast = useToast();

  if (!enabled) {
    return (
      <Panel
        id="two-factor"
        title="Two-factor authentication"
        actions={<Badge tone="neutral" icon="minus">off</Badge>}
      >
        <p className="text-body-sm text-n-700">
          {name} signs in with a password alone. Only they can turn a second factor on.
        </p>
      </Panel>
    );
  }

  return (
    <Panel
      id="two-factor"
      title="Two-factor authentication"
      description="Remove it only when this person has lost the device and their recovery codes — and only when you know who you are talking to."
      actions={<Badge tone="success" icon="check">on</Badge>}
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <p className="max-w-prose text-body-sm text-n-700">
        Removing it does not sign {name} out and does not change their password — it means their
        password alone gets them in again, until they set up a new device. It is recorded in the
        audit log of every organization they belong to, under your name.
      </p>

      <div className="mt-4">
        <Button
          variant="destructive"
          size="sm"
          disabled={busy}
          onClick={() =>
            startAction(async () => {
              const result = await revokeMfa(membershipId);

              setError(result.error);

              if (result.error === null) {
                toast({ tone: "removed", message: `Two-factor removed for ${name}.` });
              }
            })
          }
        >
          {busy ? "Removing…" : "Remove two-factor"}
        </Button>
      </div>
    </Panel>
  );
}

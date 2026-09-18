"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { reauthenticate } from "@/features/auth/reauth";

/**
 * "Confirm your password to do this" (ADR 0034).
 *
 * Appears in place, under the control that was refused, and carries out the
 * original act itself once the password is accepted. The alternative — a
 * refusal that says "confirm your password" and leaves somebody to find the
 * confirmation elsewhere and then come back and press the button again — is
 * how a safeguard becomes the thing people route around.
 *
 * It says what it is protecting, in the caller's words, because a password
 * prompt with no subject is indistinguishable from a phishing page: somebody
 * should be able to read WHY this box appeared before they type into it.
 */
export function ConfirmPassword({
  action,
  onConfirmed,
}: {
  /** What was refused, in a few words: "erase Tono Hartono". */
  action: string;
  /** Run again once the password is accepted. */
  onConfirmed: () => void;
}) {
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();

  return (
    <div className="mt-3 rounded-lg border border-s-active/30 bg-s-active/5 p-3">
      <p className="mb-2 max-w-prose text-body-sm text-n-900">
        Confirm your password to {action}. You were asked because it has been a while since you
        signed in — not because anything is wrong.
      </p>

      {error && (
        <p role="alert" className="mb-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <div className="flex flex-wrap items-end gap-2">
        <Field id="confirm-password" label="Password">
          <input
            id="confirm-password"
            type="password"
            value={password}
            autoComplete="current-password"
            autoFocus
            disabled={busy}
            onChange={(event) => setPassword(event.target.value)}
            className={INPUT.replace("w-full", "w-56")}
          />
        </Field>

        <Button
          variant="primary"
          size="sm"
          disabled={busy || password === ""}
          onClick={() =>
            startAction(async () => {
              const form = new FormData();

              form.append("password", password);

              const result = await reauthenticate(form);

              setError(result.error);

              if (result.error === null) {
                setPassword("");
                onConfirmed();
              }
            })
          }
        >
          {busy ? "Confirming…" : "Confirm"}
        </Button>
      </div>
    </div>
  );
}

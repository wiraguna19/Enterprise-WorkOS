"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { ConfirmPassword } from "@/components/ui/ConfirmPassword";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { erasePerson } from "./roles";

/**
 * Erasing a person from this organization (ADR 0022).
 *
 * Three things this control owes the person pressing it, none of them
 * decoration:
 *
 * - **It says what actually happens.** "Erase" here is anonymisation: the work,
 *   comments and history stay and stop being about a named person. An admin who
 *   believes they are deleting a year of work will not press it; one who
 *   believes the work vanishes with the person will press it by mistake.
 * - **It cannot be pressed by accident.** The name has to be typed. This is the
 *   only irreversible act in the product, and the only one with no undo to
 *   offer afterwards.
 * - **It says nothing once it is done.** The section disappears, because an
 *   erased person has nothing left to erase and a greyed-out button is an
 *   invitation to wonder.
 */
export function ErasePerson({
  membershipId,
  name,
  erasedAt,
}: {
  membershipId: string;
  name: string;
  erasedAt: string | null;
}) {
  const [error, setError] = useState<string | null>(null);
  const [needsPassword, setNeedsPassword] = useState(false);
  const [typed, setTyped] = useState("");
  const [busy, startAction] = useTransition();
  const toast = useToast();

  const erase = () =>
    startAction(async () => {
      const result = await erasePerson(membershipId);

      // "Confirm your password" is a form, not red text (ADR 0034). The typed
      // name is kept while it is answered: making somebody type it twice to
      // satisfy two different confirmations is how a deliberate act becomes a
      // chore people learn to rush.
      setNeedsPassword(result.needsPassword === true);
      setError(result.needsPassword === true ? null : result.error);

      if (result.error === null) {
        setTyped("");
        toast({
          tone: "removed",
          message: `${name} is erased. The audit log keeps a record that it happened.`,
        });
      }
    });

  if (erasedAt !== null) {
    return (
      <Panel id="erased" title="Erased" tone="danger">
        <p className="text-body-sm text-n-700">
          This person was erased from this organization. Their work, comments and history remain;
          nothing here identifies them any more, except the append-only activity and audit records,
          which age out on the retention window.
        </p>
      </Panel>
    );
  }

  return (
    <Panel
      id="erase"
      title="Erase this person"
      tone="danger"
      description="Permanent, and there is no undo."
    >
      <p className="text-body-sm text-n-700">
        Removes their name, address and profile from this organization and revokes their access.
        Their work items, comments and the history of what they did stay — they stop being about a
        named person.
      </p>

      <p className="mt-2 text-body-sm text-n-500">
        Two records are not touched: the activity history and the security audit log are
        append-only at the database level, so their name remains in those until they age out on the
        retention window. This erasure is itself recorded there.
      </p>

      {error && (
        <p
          role="alert"
          className="mt-3 border border-s-danger/40 px-3 py-2 text-body-sm text-s-danger rounded-md"
        >
          {error}
        </p>
      )}

      <form
        className="mt-3 flex flex-wrap items-end gap-3"
        onSubmit={(event) => {
          event.preventDefault();

          erase();
        }}
      >
        <Field id="erase-confirm" label={`Type “${name}” to confirm`}>
          <input
            id="erase-confirm"
            className={INPUT}
            value={typed}
            autoComplete="off"
            onChange={(event) => setTyped(event.target.value)}
          />
        </Field>

        <Button type="submit" variant="danger" size="sm" disabled={busy || typed.trim() !== name}>
          Erase
        </Button>
      </form>

      {needsPassword && (
        <ConfirmPassword
          action={`erase ${name}`}
          onConfirmed={() => {
            setNeedsPassword(false);
            erase();
          }}
        />
      )}
    </Panel>
  );
}

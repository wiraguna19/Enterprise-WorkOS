"use client";

import { useId, useState, useTransition } from "react";
import { Avatar } from "@/components/ui/Avatar";
import { Button } from "@/components/ui/Button";
import { assignTo, unassign } from "../actions";

type Person = { id: string; name: string };

/**
 * Changing who holds a role, from the item itself (docs/08 §4).
 *
 * It belongs here rather than on the edit form, and the reason is what the two
 * screens are for: editing is a considered pass over an item's fields, while
 * handing work to somebody is a single decision made while looking at the
 * work. Burying it in a form behind a button called "Edit" would make the most
 * common change in a tracker the least reachable one.
 *
 * Reassigning is ONE call. The API moves the role from whoever held it, so
 * there is no moment when the item belongs to nobody, and the history reads as
 * one decision instead of two.
 *
 * Clearing is a separate control, deliberately not an option in the list.
 * "Nobody" is a decision — a row nobody owns is how work goes quiet — and it
 * should cost a different click from choosing a colleague.
 */
export function AssigneePicker({
  reference,
  role,
  current,
  people,
  canAssign,
}: {
  reference: string;
  role: "assignee" | "reviewer";
  current: { assignment_id: string; membership_id: string; name: string | null } | null;
  people: Person[];
  canAssign: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saving, startTransition] = useTransition();
  const selectId = useId();

  const act = (run: () => Promise<{ error: string | null }>) =>
    startTransition(async () => {
      const result = await run();

      setError(result.error);

      if (result.error === null) setOpen(false);
    });

  if (!canAssign) {
    return current === null ? (
      <span className="text-s-active">Unassigned</span>
    ) : (
      <PersonLabel membershipId={current.membership_id} name={current.name} />
    );
  }

  if (!open) {
    return (
      <span className="inline-flex items-center gap-2">
        {current === null ? (
          <span className="text-s-active">Unassigned</span>
        ) : (
          <PersonLabel membershipId={current.membership_id} name={current.name} />
        )}

        {/* The visible word is short because the row already says which field
            it belongs to. The accessible name says the role out loud: two
            buttons called "Change" on one screen are two buttons a screen
            reader cannot tell apart. */}
        <button
          type="button"
          aria-label={`${current === null ? "Assign" : "Change"} ${role}`}
          onClick={() => {
            setError(null);
            setOpen(true);
          }}
          className="text-caption text-n-500 underline-offset-2 hover:text-n-900 hover:underline"
        >
          {current === null ? "Assign" : "Change"}
        </button>

        {error !== null && (
          <span role="alert" className="text-caption text-s-danger">
            {error}
          </span>
        )}
      </span>
    );
  }

  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      <label htmlFor={selectId} className="sr-only">
        {role === "assignee" ? "Assignee" : "Reviewer"}
      </label>

      <select
        id={selectId}
        defaultValue=""
        disabled={saving}
        onChange={(event) => {
          const membershipId = event.target.value;

          if (membershipId !== "") act(() => assignTo(reference, membershipId, role));
        }}
        className="rounded-sm border border-n-200 bg-n-0 px-1.5 py-1 text-body-sm text-n-900"
      >
        <option value="">{saving ? "Saving…" : "Choose someone…"}</option>
        {people
          // Whoever holds it is not offered: the API refuses it as "already
          // holds this role", and an option that can only fail is a trap.
          .filter((person) => person.id !== current?.membership_id)
          .map((person) => (
            <option key={person.id} value={person.id}>
              {person.name}
            </option>
          ))}
      </select>

      {current !== null && (
        <Button
          size="sm"
          variant="ghost"
          aria-label={`Clear ${role}`}
          disabled={saving}
          onClick={() => act(() => unassign(reference, current.assignment_id))}
        >
          Clear
        </Button>
      )}

      <Button
        size="sm"
        variant="ghost"
        aria-label={`Stop changing the ${role}`}
        disabled={saving}
        onClick={() => setOpen(false)}
      >
        Cancel
      </Button>

      {error !== null && (
        <span role="alert" className="text-caption text-s-danger">
          {error}
        </span>
      )}
    </span>
  );
}

function PersonLabel({ membershipId, name }: { membershipId: string; name: string | null }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      <Avatar id={membershipId} name={name ?? "?"} size="sm" />
      {name}
    </span>
  );
}

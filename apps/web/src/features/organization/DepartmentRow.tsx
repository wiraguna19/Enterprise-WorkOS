"use client";

import { useId, useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { INPUT } from "@/components/ui/Field";
import { moveDepartment, renameDepartment } from "./actions";

export type DepartmentNode = {
  id: string;
  name: string;
  code: string | null;
  depth: number;
  parent_id: string | null;
};

/**
 * One department, and the two things that can be done to it.
 *
 * Renaming and moving are separate controls because they are separate
 * decisions: a rename is a correction, and a move re-draws the reporting line
 * every person under it inherits. Putting both behind one "Edit" would let
 * somebody fix a typo and re-parent a division in the same click.
 *
 * Neither is optimistic (ADR 0012). The row does not shift on click; the server
 * action returns, the page revalidates, and the tree re-renders from the API's
 * answer — which matters here more than most places, because the API refuses
 * moves this screen deliberately does not pre-check.
 */
export function DepartmentRow({
  department,
  options,
}: {
  department: DepartmentNode;
  /** Every other department, as a possible parent. */
  options: Array<{ id: string; label: string }>;
}) {
  const [renaming, setRenaming] = useState(false);
  const [name, setName] = useState(department.name);
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();

  const nameId = useId();
  const parentId = useId();

  const run = (action: () => Promise<{ error: string | null }>): void => {
    setError(null);
    startAction(async () => {
      const result = await action();

      setError(result.error);

      if (result.error === null) setRenaming(false);
    });
  };

  return (
    <li className="border-b border-n-100 py-2 last:border-b-0">
      <div
        className="flex flex-wrap items-center gap-x-3 gap-y-2"
        // Indentation is the only thing on this screen that shows the shape of
        // the tree, and it is inline because the depth is data rather than one
        // of a handful of classes Tailwind could name ahead of time.
        style={{ paddingLeft: `${department.depth * 1.25}rem` }}
      >
        <span className="w-24 shrink-0 truncate font-mono text-caption text-n-500">
          {department.code ?? "—"}
        </span>

        {renaming ? (
          <form
            className="flex min-w-0 flex-1 items-center gap-2"
            onSubmit={(event) => {
              event.preventDefault();
              run(() => renameDepartment(department.id, name));
            }}
          >
            <label htmlFor={nameId} className="sr-only">
              Name of {department.name}
            </label>
            <input
              id={nameId}
              type="text"
              value={name}
              onChange={(event) => setName(event.target.value)}
              minLength={2}
              maxLength={120}
              required
              autoFocus
              className={`${INPUT} max-w-xs`}
            />
            <Button type="submit" variant="primary" size="sm" disabled={busy}>
              {busy ? "Saving…" : "Save"}
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={busy}
              onClick={() => {
                // Back to what the server last said, not to whatever was typed
                // before the cancel — a cancelled edit that leaves its draft
                // behind is how the next save writes something nobody chose.
                setName(department.name);
                setRenaming(false);
                setError(null);
              }}
            >
              Cancel
            </Button>
          </form>
        ) : (
          <>
            <span className="min-w-0 flex-1 truncate font-medium text-n-900">
              {department.name}
            </span>

            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => setRenaming(true)}
            >
              Rename
            </Button>
          </>
        )}

        <label htmlFor={parentId} className="sr-only">
          Reports into, for {department.name}
        </label>
        <select
          id={parentId}
          defaultValue={department.parent_id ?? ""}
          disabled={busy}
          onChange={(event) => run(() => moveDepartment(department.id, event.target.value || null))}
          className={`${INPUT} w-auto max-w-[14rem] text-caption`}
        >
          <option value="">A top-level department</option>
          {options.map((option) => (
            <option key={option.id} value={option.id}>
              {option.label}
            </option>
          ))}
        </select>
      </div>

      {error && (
        <p role="alert" className="mt-1 text-caption text-s-danger" style={{ paddingLeft: `${department.depth * 1.25}rem` }}>
          {error}
        </p>
      )}
    </li>
  );
}

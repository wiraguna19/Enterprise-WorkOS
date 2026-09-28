"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { formatDate } from "@/lib/format";
import { restoreWorkItem } from "../actions";

/**
 * "This is archived" — and the way back (ADR 0054).
 *
 * Archived work has left boards and lists, not the product: it still counts in
 * every report and opens here by its reference. The notice says both, because
 * somebody who followed an old link to a finished item should not wonder
 * whether it was deleted.
 */
export function ArchivedNotice({
  reference,
  archivedAt,
  canRestore,
  timeZone,
}: {
  reference: string;
  archivedAt: string;
  canRestore: boolean;
  timeZone: string;
}) {
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();

  return (
    <div
      role="note"
      className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-n-200 bg-n-50 px-3 py-2 text-body-sm text-n-700"
    >
      <p className="max-w-prose">
        Archived {formatDate(archivedAt, timeZone)}. Closed work leaves boards and lists after a
        while here; it still counts in every report and opens from its reference.
        {error && <span className="ml-1 text-s-danger">{error}</span>}
      </p>

      {canRestore && (
        <Button
          size="sm"
          disabled={busy}
          onClick={() =>
            start(async () => {
              setError((await restoreWorkItem(reference)).error);
            })
          }
        >
          {busy ? "Restoring…" : "Bring back to the board"}
        </Button>
      )}
    </div>
  );
}

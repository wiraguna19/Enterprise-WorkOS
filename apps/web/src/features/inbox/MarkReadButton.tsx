"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { markRead } from "./actions";

/**
 * The other half of the badge (docs/08 §7).
 *
 * Two shapes, one action, because they are the same thing at two scales: the
 * row control clears one notification, the header control clears the lot.
 * Passing no ids is not a shortcut for "all the ids on screen" — the list is
 * paged, so a client-enumerated "all" would clear the page and leave the badge
 * stubbornly non-zero, which is the behaviour this is here to end.
 *
 * Nothing is optimistic (ADR 0012). The row does not fade on click; the action
 * returns, the layout revalidates, and the list re-renders from the API's own
 * answer. A count that lies for 200ms is how a badge loses its credibility a
 * second time.
 */
export function MarkReadButton({
  ids,
  label,
  busyLabel,
  variant = "ghost",
}: {
  /** Omitted means every unread notification, decided by the server. */
  ids?: string[];
  label: string;
  busyLabel: string;
  variant?: "primary" | "secondary" | "ghost";
}) {
  const [error, setError] = useState<string | null>(null);
  const [submitting, startTransition] = useTransition();

  return (
    <span className="inline-flex items-center gap-2">
      {error && (
        <span role="alert" className="text-caption text-s-danger">
          {error}
        </span>
      )}

      <Button
        variant={variant}
        size="sm"
        disabled={submitting}
        onClick={() =>
          startTransition(async () => {
            const result = await markRead(ids);
            setError(result.error);
          })
        }
      >
        {submitting ? busyLabel : label}
      </Button>
    </span>
  );
}

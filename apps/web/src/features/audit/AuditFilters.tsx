"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";

/**
 * Three filters, and the reason there are only three.
 *
 * WHEN, WHO and WHAT KIND are the axes `audit_logs` is indexed on. Anything
 * else — a free-text search over metadata, say — reads well on a design and
 * scans a partitioned table with millions of rows in it.
 *
 * They live in the URL rather than in component state, so a filtered view is a
 * link somebody can paste into an incident thread. That is most of what this
 * screen is for.
 */
export function AuditFilters({ event, since }: { event: string; since: string }) {
  const router = useRouter();
  const params = useSearchParams();

  const [draftEvent, setDraftEvent] = useState(event);
  const [draftSince, setDraftSince] = useState(since);

  return (
    <form
      className="flex flex-wrap items-end gap-3"
      onSubmit={(submitted) => {
        submitted.preventDefault();

        const next = new URLSearchParams(params.toString());

        // Removed rather than set empty: `?event=` in a pasted link is a
        // filter for the empty string, and the API would take it literally.
        for (const [key, value] of [
          ["event", draftEvent],
          ["since", draftSince],
        ] as const) {
          if (value === "") {
            next.delete(key);
          } else {
            next.set(key, value);
          }
        }

        router.push(`/settings/audit?${next.toString()}`);
      }}
    >
      <Field id="event" label="Event" hint="A prefix works: invitation., auth., role.">
        <input
          id="event"
          className={INPUT}
          value={draftEvent}
          onChange={(changed) => setDraftEvent(changed.target.value)}
        />
      </Field>

      <Field id="since" label="Since">
        <input
          id="since"
          type="date"
          className={INPUT}
          value={draftSince}
          onChange={(changed) => setDraftSince(changed.target.value)}
        />
      </Field>

      <Button type="submit" variant="secondary" size="sm">
        Filter
      </Button>
    </form>
  );
}

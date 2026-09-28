"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { setArchivePolicy } from "./actions";

/**
 * How long closed work stays in view (ADR 0054).
 *
 * The sentence the screen has to carry is what archiving is NOT: nothing is
 * deleted, nothing leaves a report, every item still opens from its reference
 * and comes back with one click. Without it, "archive after 30 days" reads
 * like a retention policy, and somebody sets it to "never" out of caution and
 * keeps a Done column of ten thousand cards.
 */
const CHOICES: Array<{ value: string; label: string }> = [
  { value: "30", label: "30 days" },
  { value: "60", label: "60 days" },
  { value: "90", label: "90 days" },
  { value: "180", label: "6 months" },
  { value: "365", label: "A year" },
  { value: "", label: "Never" },
];

export function ArchivePolicyForm({
  current,
  editable,
}: {
  current: number | null;
  editable: boolean;
}) {
  const [value, setValue] = useState(current === null ? "" : String(current));
  const [error, setError] = useState<string | null>(null);
  const [busy, start] = useTransition();
  const toast = useToast();

  // A value set some other way (the API, a script) that is not one of the
  // choices is still shown, rather than silently displayed as the nearest.
  const choices = CHOICES.some((choice) => choice.value === value)
    ? CHOICES
    : [{ value, label: `${value} days` }, ...CHOICES];

  const save = () =>
    start(async () => {
      const days = value === "" ? null : Number(value);
      const result = await setArchivePolicy(days);

      setError(result.error);

      if (result.error === null) {
        toast({
          message:
            days === null
              ? "Closed work stays on boards and lists."
              : `Closed work leaves boards and lists after ${days} days untouched.`,
        });
      }
    });

  return (
    <Panel
      id="archive-policy"
      title="Closed work"
      description="How long finished and cancelled work stays on boards and lists."
      actions={
        current === null ? (
          <Badge tone="neutral" icon="minus">never archived</Badge>
        ) : (
          <Badge tone="neutral">after {current} days</Badge>
        )
      }
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <div className="max-w-prose space-y-3">
        <p className="text-body-sm text-n-700">
          Work that has been done or cancelled — and untouched — for this long is archived every
          night. Nothing is deleted: it still counts in every report, opens from its reference, and
          comes back to its board with one click. Reopening it brings it back by itself.
        </p>

        {editable && (
          <div className="flex flex-wrap items-end gap-2">
            <Field id="archive-after" label="Archive after">
              <select
                id="archive-after"
                value={value}
                disabled={busy}
                onChange={(event) => setValue(event.target.value)}
                className={INPUT.replace("w-full", "w-40")}
              >
                {choices.map((choice) => (
                  <option key={choice.value} value={choice.value}>
                    {choice.label}
                  </option>
                ))}
              </select>
            </Field>

            <Button
              variant="primary"
              size="sm"
              disabled={busy || value === (current === null ? "" : String(current))}
              onClick={save}
            >
              {busy ? "Saving…" : "Save"}
            </Button>
          </div>
        )}
      </div>
    </Panel>
  );
}

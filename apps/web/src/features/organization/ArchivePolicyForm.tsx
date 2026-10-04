"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { setArchivePolicy } from "./actions";
import { useT } from "@/i18n/I18nProvider";
import type { MessageKey } from "@/i18n/messages/en";

/**
 * How long closed work stays in view (ADR 0054).
 *
 * The sentence the screen has to carry is what archiving is NOT: nothing is
 * deleted, nothing leaves a report, every item still opens from its reference
 * and comes back with one click. Without it, "archive after 30 days" reads
 * like a retention policy, and somebody sets it to "never" out of caution and
 * keeps a Done column of ten thousand cards.
 */
// A day count is labelled by the plural rule; the round spans have names of
// their own, because "180 days" is a number the reader has to convert.
const CHOICES: Array<{ value: string; label?: MessageKey }> = [
  { value: "30" },
  { value: "60" },
  { value: "90" },
  { value: "180", label: "arch.choice.180" },
  { value: "365", label: "arch.choice.365" },
  { value: "", label: "arch.never" },
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
  const t = useT();

  // A value set some other way (the API, a script) that is not one of the
  // choices is still shown, rather than silently displayed as the nearest.
  const choices = CHOICES.some((choice) => choice.value === value)
    ? CHOICES
    : [{ value }, ...CHOICES];

  const labelOf = (choice: { value: string; label?: MessageKey }): string =>
    choice.label !== undefined ? t(choice.label) : t.plural("unit.days", Number(choice.value));

  const save = () =>
    start(async () => {
      const days = value === "" ? null : Number(value);
      const result = await setArchivePolicy(days);

      setError(result.error);

      if (result.error === null) {
        toast({
          message:
            days === null
              ? t("arch.toast.never")
              : t("arch.toast.after", { count: days }),
        });
      }
    });

  return (
    <Panel
      id="archive-policy"
      title={t("arch.title")}
      description={t("arch.description")}
      actions={
        current === null ? (
          <Badge tone="neutral" icon="minus">{t("arch.badge.never")}</Badge>
        ) : (
          <Badge tone="neutral">{t("arch.badge.after", { count: current })}</Badge>
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
          {t("arch.body")}
        </p>

        {editable && (
          <div className="flex flex-wrap items-end gap-2">
            <Field id="archive-after" label={t("arch.after")}>
              <select
                id="archive-after"
                value={value}
                disabled={busy}
                onChange={(event) => setValue(event.target.value)}
                className={INPUT.replace("w-full", "w-40")}
              >
                {choices.map((choice) => (
                  <option key={choice.value} value={choice.value}>
                    {labelOf(choice)}
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
              {busy ? t("common.saving") : t("common.save")}
            </Button>
          </div>
        )}
      </div>
    </Panel>
  );
}

"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { ConfirmPassword } from "@/components/ui/ConfirmPassword";
import { Field } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { setSessionPolicy } from "./actions";
import { useLocale, useT } from "@/i18n/I18nProvider";
import { minutesName } from "@/i18n/labels";
import type { MessageKey } from "@/i18n/messages/en";

/**
 * How long a session may live in this organization (ADR 0028).
 *
 * Presets rather than a free number box. The bounds are 1 and 90, and every
 * value between them is legal, but offering 47 as readily as 30 invites a
 * choice nobody can justify — and the number that matters is which end of the
 * range you are near, not the digit. "Custom" is not owed here; the API takes
 * any integer in range for whoever needs one.
 *
 * The consequence is stated BEFORE the button, not after: shortening the window
 * pulls back sessions that already exist, and an administrator who learns that
 * from the people who were signed out has learned it too late.
 */
// Labels are built from the number at render, in the reader's language; only
// the notes are words of their own.
const CHOICES: Array<{ days: number; note?: MessageKey }> = [
  { days: 1, note: "sesspol.choice.1" },
  { days: 7, note: "sesspol.choice.7" },
  { days: 14 },
  { days: 30, note: "sesspol.choice.30" },
  { days: 60 },
  { days: 90, note: "sesspol.longest" },
];

/**
 * The idle window, in minutes, and "never" as a real answer (ADR 0029).
 *
 * Off is the first entry and the default, because switching it on signs out
 * everybody who has stepped away — a thing somebody should choose, not inherit.
 */
const IDLE_CHOICES: Array<{ minutes: number | null; note?: MessageKey }> = [
  { minutes: null, note: "sesspol.idle.never.note" },
  { minutes: 30 },
  { minutes: 60 },
  { minutes: 480, note: "sesspol.idle.workday" },
  { minutes: 1440 },
  { minutes: 10080, note: "sesspol.longest" },
];

export function SessionPolicyForm({
  current,
  currentIdle,
  editable,
}: {
  current: number;
  currentIdle: number | null;
  /** False for somebody who may read this page but not change it. */
  editable: boolean;
}) {
  const [days, setDays] = useState(current);
  const [idle, setIdle] = useState<number | null>(currentIdle);
  const [error, setError] = useState<string | null>(null);
  const [needsPassword, setNeedsPassword] = useState(false);
  const [busy, startAction] = useTransition();
  const toast = useToast();
  const t = useT();
  const locale = useLocale();

  const save = () =>
    startAction(async () => {
      const result = await setSessionPolicy(days, idle);

      setNeedsPassword(result.needsPassword === true);
      setError(result.needsPassword === true ? null : result.error);

      if (result.error === null) {
        toast({
          tone: result.shortened > 0 ? "removed" : "done",
          message:
            result.shortened > 0
              ? t.plural("sesspol.toast.shortened", result.shortened, {
                  duration: t.plural("unit.days", days),
                })
              : t("sesspol.toast", { duration: t.plural("unit.days", days) }),
        });
      }
    });

  const changed = days !== current || idle !== currentIdle;
  const shortening = days < current;
  // Tightening the idle window bites on the next request rather than at save
  // time, so it is worth saying out loud before the button is pressed.
  const tightening =
    idle !== currentIdle && idle !== null && (currentIdle === null || idle < currentIdle);

  return (
    <Panel
      id="session-policy"
      title={t("sesspol.title")}
      description={t("sesspol.description")}
    >
      {error && (
        <p role="alert" className="mb-3 rounded-md border border-s-danger/40 bg-s-danger/5 px-3 py-2 text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <div className="max-w-md space-y-3">
        <Field
          id="session-lifetime"
          label={t("org.sessionsLast")}
          hint={editable ? t("sesspol.lifetime.hint") : t("sesspol.noPermission")}
        >
          <select
            id="session-lifetime"
            value={days}
            disabled={!editable || busy}
            onChange={(event) => setDays(Number(event.target.value))}
            className="w-56 rounded-md border border-n-300 bg-n-0 px-2 py-1.5 text-body-sm text-n-900 focus:border-a-500 focus:outline-none focus:ring-2 focus:ring-a-500/30 disabled:bg-n-50 disabled:text-n-500"
          >
            {CHOICES.map((choice) => (
              <option key={choice.days} value={choice.days}>
                {t.plural("unit.days", choice.days)}
                {choice.note ? ` — ${t(choice.note)}` : ""}
              </option>
            ))}
          </select>
        </Field>

        <Field
          id="idle-timeout"
          label={t("sesspol.idle")}
          hint={editable ? t("sesspol.idle.hint") : t("sesspol.noPermission")}
        >
          <select
            id="idle-timeout"
            value={idle === null ? "never" : String(idle)}
            disabled={!editable || busy}
            onChange={(event) =>
              setIdle(event.target.value === "never" ? null : Number(event.target.value))
            }
            className="w-56 rounded-md border border-n-300 bg-n-0 px-2 py-1.5 text-body-sm text-n-900 focus:border-a-500 focus:outline-none focus:ring-2 focus:ring-a-500/30 disabled:bg-n-50 disabled:text-n-500"
          >
            {IDLE_CHOICES.map((choice) => (
              <option
                key={choice.minutes ?? "never"}
                value={choice.minutes === null ? "never" : choice.minutes}
              >
                {choice.minutes === null
                  ? t("sesspol.idle.never")
                  : t("sesspol.idle.after", { duration: idleName(choice.minutes, t) })}
                {choice.note ? ` — ${t(choice.note)}` : ""}
              </option>
            ))}
          </select>
        </Field>

        {tightening && (
          <p className="rounded-md border border-s-active/30 bg-s-active/10 px-3 py-2 text-body-sm text-s-active">
            {t("sesspol.tightening")}
          </p>
        )}

        {changed && shortening && (
          <p className="rounded-md border border-s-active/30 bg-s-active/10 px-3 py-2 text-body-sm text-s-active">
            {t("sesspol.shortening", { duration: t.plural("unit.days", days) })}
          </p>
        )}

        {editable && (
          <div className="flex items-center gap-2">
            <Button
              variant="primary"
              size="sm"
              disabled={!changed || busy}
              onClick={save}
            >
              {busy ? t("common.saving") : t("common.save")}
            </Button>

            {changed && (
              <Button
                variant="ghost"
                size="sm"
                disabled={busy}
                onClick={() => {
                  setDays(current);
                  setIdle(currentIdle);
                }}
              >
                {t("common.cancel")}
              </Button>
            )}
          </div>
        )}

        {needsPassword && (
          <ConfirmPassword
            action={t("sesspol.confirm")}
            locale={locale}
            onConfirmed={() => {
              setNeedsPassword(false);
              save();
            }}
          />
        )}
      </div>
    </Panel>
  );
}

/**
 * The idle picker's own reading of a span: hours up to a day, so "24 hours"
 * sits beside "8 hours" rather than switching unit one row down. A week is
 * the exception, because "168 hours" is a number nobody says.
 */
function idleName(minutes: number, t: ReturnType<typeof useT>): string {
  if (minutes >= 10080) return minutesName(minutes, t);
  if (minutes % 60 === 0) return t.plural("unit.hours", minutes / 60);

  return t.plural("unit.minutes", minutes);
}

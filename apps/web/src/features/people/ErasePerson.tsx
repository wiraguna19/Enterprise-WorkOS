"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { ConfirmPassword } from "@/components/ui/ConfirmPassword";
import { Field, INPUT } from "@/components/ui/Field";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { useLocale, useT } from "@/i18n/I18nProvider";
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
  const t = useT();
  const locale = useLocale();
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
          message: t("erase.toast", { name }),
        });
      }
    });

  if (erasedAt !== null) {
    return (
      <Panel id="erased" title={t("erase.erasedTitle")} tone="danger">
        <p className="text-body-sm text-n-700">
          {t("erase.erasedBody")}
        </p>
      </Panel>
    );
  }

  return (
    <Panel
      id="erase"
      title={t("erase.title")}
      tone="danger"
      description={t("erase.desc")}
    >
      <p className="text-body-sm text-n-700">
        {t("erase.body1")}
      </p>

      <p className="mt-2 text-body-sm text-n-500">
        {t("erase.body2")}
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
        <Field id="erase-confirm" label={t("erase.type", { name })}>
          <input
            id="erase-confirm"
            className={INPUT}
            value={typed}
            autoComplete="off"
            onChange={(event) => setTyped(event.target.value)}
          />
        </Field>

        <Button type="submit" variant="danger" size="sm" disabled={busy || typed.trim() !== name}>
          {t("erase.erase")}
        </Button>
      </form>

      {needsPassword && (
        <ConfirmPassword
          action={t("erase.confirmAction", { name })}
          locale={locale}
          onConfirmed={() => {
            setNeedsPassword(false);
            erase();
          }}
        />
      )}
    </Panel>
  );
}

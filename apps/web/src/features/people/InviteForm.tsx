"use client";

import Link from "next/link";
import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Panel } from "@/components/ui/Panel";
import { Field, INPUT } from "@/components/ui/Field";
import { useT } from "@/i18n/I18nProvider";
import { invitePerson, type Invitation } from "./invitations";

/**
 * Inviting somebody, and handing over the link (ADR 0017).
 *
 * There is no mail layer in this product, so the invitation is not sent — it is
 * HANDED to the administrator, once, to pass on however they already talk to
 * the person. The screen says that plainly rather than implying an email is on
 * its way, which is the difference between a limitation and a lie.
 *
 * The link is shown once because only its digest is stored. Saying so where the
 * link is, rather than in a tooltip or a doc, is the only place the sentence
 * can do any good.
 */
export function InviteForm({ roles }: { roles: Array<{ key: string; name: string }> }) {
  const t = useT();
  const [email, setEmail] = useState("");
  const [role, setRole] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [issued, setIssued] = useState<Invitation | null>(null);
  const [busy, startAction] = useTransition();

  if (issued) {
    return (
      // The issued link is its own panel: it is the one thing on the screen,
      // it is shown once, and a bordered surface is what says "this is the
      // thing" rather than "here is some text after a form" (ADR 0024).
      <Panel
        id="invitation"
        title={t("invite.ready", { email: issued.email })}
        description={t("invite.readyDesc")}
      >
      <div className="space-y-3">

        <p className="text-body-sm text-n-500">
          {t("invite.noMail.before")} <strong>{t("invite.noMail.strong")}</strong>{" "}
          {t("invite.noMail.after")}
        </p>

        <code className="block overflow-x-auto break-all rounded-lg border border-n-300 bg-n-50 p-3 font-mono text-micro text-n-900">
          {inviteUrl(issued.token)}
        </code>

        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => navigator.clipboard?.writeText(inviteUrl(issued.token))}
          >
            {t("invite.copy")}
          </Button>

          <Button variant="ghost" size="sm" onClick={() => setIssued(null)}>
            {t("invite.another")}
          </Button>

          <Link href="/people" className="text-body-sm text-a-700 underline">
            {t("invite.back")}
          </Link>
        </div>
      </div>
      </Panel>
    );
  }

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();

        startAction(async () => {
          const result = await invitePerson({ email, role: role === "" ? null : role });

          setError(result.error);

          if (result.invitation) setIssued(result.invitation);
        });
      }}
    >
      <Panel
        id="invite"
        title={t("invite.formTitle")}
        description={t("invite.formDesc")}
        // The error stays in the BODY, beside the field it is about, rather
        // than being repeated down here: this form has one input, and an error
        // in two places is an error somebody reads twice and believes once.
        footer={
          <Button type="submit" variant="primary" disabled={busy || email === ""}>
            {busy ? t("pnew.creating") : t("invite.create")}
          </Button>
        }
      >
      <div className="space-y-4">
      {error && (
        <p
          role="alert"
          className="border border-s-danger/40 px-3 py-2 text-body-sm text-s-danger rounded-md"
        >
          {error}
        </p>
      )}

      <Field id="email" label={t("login.email")} hint={t("invite.emailHint")}>
        <input
          id="email"
          type="email"
          className={INPUT}
          value={email}
          required
          onChange={(event) => setEmail(event.target.value)}
        />
      </Field>

      <Field
        id="role"
        label={t("members.col.role")}
        hint={t("invite.roleHint")}
      >
        <select
          id="role"
          className={INPUT}
          value={role}
          onChange={(event) => setRole(event.target.value)}
        >
          <option value="">{t("invite.decideLater")}</option>
          {roles.map((entry) => (
            <option key={entry.key} value={entry.key}>
              {entry.name}
            </option>
          ))}
        </select>
      </Field>

      </div>
      </Panel>
    </form>
  );
}

/**
 * Built here rather than by the API: the API does not know this app's URL.
 *
 * The token goes in the FRAGMENT: browsers never send it to a server, so it
 * stays out of every access log between the newcomer and this app.
 */
function inviteUrl(token: string): string {
  const origin = typeof window === "undefined" ? "" : window.location.origin;

  return `${origin}/invite#${token}`;
}

"use client";

import Link from "next/link";
import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { Panel } from "@/components/ui/Panel";
import { Field, INPUT } from "@/components/ui/Field";
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
        title={`Invitation ready for ${issued.email}`}
        description="Shown once — only a digest of it is stored."
      >
      <div className="space-y-3">

        <p className="text-body-sm text-n-500">
          Nothing was emailed — this product has no mail of its own yet. Send them this link
          yourself. <strong>It is shown once:</strong> only a digest of it is stored, so if it is
          lost the invitation has to be revoked and reissued.
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
            Copy the link
          </Button>

          <Button variant="ghost" size="sm" onClick={() => setIssued(null)}>
            Invite somebody else
          </Button>

          <Link href="/people" className="text-body-sm text-a-700 underline">
            Back to people
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
        title="Invite somebody"
        description="They choose their own password from the link; the role can wait until they are in."
        // The error stays in the BODY, beside the field it is about, rather
        // than being repeated down here: this form has one input, and an error
        // in two places is an error somebody reads twice and believes once.
        footer={
          <Button type="submit" variant="primary" disabled={busy || email === ""}>
            {busy ? "Creating…" : "Create the invitation"}
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

      <Field id="email" label="Email" hint="Where they already read their work mail.">
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
        label="Role"
        hint="Optional. Somebody can join before anyone has decided what they will do."
      >
        <select
          id="role"
          className={INPUT}
          value={role}
          onChange={(event) => setRole(event.target.value)}
        >
          <option value="">Decide later</option>
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

/** Built here rather than by the API: the API does not know this app's URL. */
function inviteUrl(token: string): string {
  const origin = typeof window === "undefined" ? "" : window.location.origin;

  return `${origin}/invite/${token}`;
}

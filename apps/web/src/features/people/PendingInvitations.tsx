"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { revokeInvitation } from "./invitations";

/**
 * Invitations still waiting (ADR 0017).
 *
 * Expired ones are shown rather than hidden: "I sent that a fortnight ago and
 * heard nothing" is only answerable if the row is still there. Revoking is the
 * only control, and it is also the answer to "they lost the link" — the token
 * is a digest in the database and cannot be shown again.
 */
export type Pending = {
  id: string;
  email: string;
  role_name: string | null;
  expires_at: string;
  has_expired: boolean;
};

export function PendingInvitations({ invitations }: { invitations: Pending[] }) {
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();

  if (invitations.length === 0) return null;

  return (
    <section aria-labelledby="invitations-heading" className="space-y-2">
      <h2 id="invitations-heading" className="text-h2 font-semibold text-n-900">
        Waiting to accept
      </h2>

      {error && (
        <p role="alert" className="text-body-sm text-s-danger">
          {error}
        </p>
      )}

      <ul className="divide-y divide-n-100 border-y border-n-100">
        {invitations.map((invitation) => (
          <li key={invitation.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2">
            <span className="min-w-0 flex-1 truncate text-body-sm text-n-900">
              {invitation.email}
            </span>

            <span className="text-caption text-n-500">{invitation.role_name ?? "no role yet"}</span>

            <span className="w-28 shrink-0 text-caption text-n-500">
              {invitation.has_expired ? "expired" : "waiting"}
            </span>

            <Button
              variant="ghost"
              size="sm"
              disabled={busy}
              onClick={() =>
                startAction(async () => {
                  const result = await revokeInvitation(invitation.id);

                  setError(result.error);
                })
              }
            >
              Revoke
            </Button>
          </li>
        ))}
      </ul>
    </section>
  );
}

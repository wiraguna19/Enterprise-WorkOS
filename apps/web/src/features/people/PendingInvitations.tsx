"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
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
  const toast = useToast();

  if (invitations.length === 0) return null;

  const expired = invitations.filter((invitation) => invitation.has_expired).length;

  return (
    <Panel
      id="invitations"
      title="Waiting to accept"
      description="Revoking is also the answer to “they lost the link”: the token is a digest and cannot be shown again."
      actions={expired > 0 ? <Badge tone="warning">{expired} expired</Badge> : undefined}
      bleed
    >
      {error && (
        <p
          role="alert"
          className="border-b border-s-danger/40 bg-s-danger/5 px-4 py-2 text-body-sm text-s-danger"
        >
          {error}
        </p>
      )}

      <DataTable caption="Invitations that have not been accepted">
        <THead>
          <Tr>
            <Th>Address</Th>
            <Th>Role</Th>
            <Th>State</Th>
            <Th width="w-24" align="right">
              Action
            </Th>
          </Tr>
        </THead>
        <TBody>
          {invitations.map((invitation) => (
            <Tr key={invitation.id}>
              <Td>{invitation.email}</Td>
              <Td muted>{invitation.role_name ?? "no role yet"}</Td>
              <Td>
                {invitation.has_expired ? (
                  <Badge tone="warning">expired</Badge>
                ) : (
                  <Badge>waiting</Badge>
                )}
              </Td>
              <Td align="right">
                <Button
                  variant="destructive"
                  size="sm"
                  disabled={busy}
                  onClick={() =>
                    startAction(async () => {
                      const result = await revokeInvitation(invitation.id);

                      setError(result.error);

                      if (result.error === null) {
                        toast({ tone: "removed", message: "Revoked. That link no longer opens anything." });
                      }
                    })
                  }
                >
                  Revoke
                </Button>
              </Td>
            </Tr>
          ))}
        </TBody>
      </DataTable>
    </Panel>
  );
}

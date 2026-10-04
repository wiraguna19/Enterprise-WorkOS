"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { useT } from "@/i18n/I18nProvider";
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
  const t = useT();
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();
  const toast = useToast();

  if (invitations.length === 0) return null;

  const expired = invitations.filter((invitation) => invitation.has_expired).length;

  return (
    <Panel
      id="invitations"
      title={t("inv.title")}
      description={t("inv.desc")}
      actions={
        expired > 0 ? (
          <Badge tone="warning" icon="clock">
            {t("inv.expiredCount", { count: expired })}
          </Badge>
        ) : undefined
      }
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

      <DataTable caption={t("inv.caption")}>
        <THead>
          <Tr>
            <Th>{t("inv.col.address")}</Th>
            <Th>{t("members.col.role")}</Th>
            <Th>{t("inv.col.state")}</Th>
            <Th width="w-24" align="right">
              {t("inv.col.action")}
            </Th>
          </Tr>
        </THead>
        <TBody>
          {invitations.map((invitation) => (
            <Tr key={invitation.id}>
              <Td>{invitation.email}</Td>
              <Td muted>{invitation.role_name ?? t("inv.noRole")}</Td>
              <Td>
                {invitation.has_expired ? (
                  <Badge tone="warning" icon="clock">
                    {t("inv.expired")}
                  </Badge>
                ) : (
                  <Badge>{t("inv.waiting")}</Badge>
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
                        toast({ tone: "removed", message: t("inv.revoked") });
                      }
                    })
                  }
                >
                  {t("feed.revoke")}
                </Button>
              </Td>
            </Tr>
          ))}
        </TBody>
      </DataTable>
    </Panel>
  );
}

"use client";

import { useState, useTransition } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { Panel } from "@/components/ui/Panel";
import { useToast } from "@/components/ui/Toast";
import { endOtherSessions, endSession } from "./actions";

/**
 * Everything signed in as you (ADR 0023).
 *
 * `sessions` has recorded an address, a user agent and a last-used time since
 * Phase 1 with nothing reading them. This is the screen that answers the
 * question the table exists for: what else is signed in as me, and can I stop
 * it.
 *
 * The current device is marked rather than hidden. A list that omitted it would
 * read as "somebody else is signed in here", and one that let it be ended
 * without saying so would sign you out of the page you are standing on.
 */
export type Session = {
  id: string;
  current: boolean;
  user_agent: string | null;
  ip_address: string | null;
  /** Already formatted in the viewer's time zone, on the server.
   *
   * Data, not a function. Passing a formatter across this boundary is what
   * broke `/settings/notifications` the first time it shipped: a Server
   * Component may hand a Client Component values, never callables, and the
   * failure is a runtime error on render rather than anything tsc sees. */
  last_used: string;
  started: string;
};

export function SessionList({ sessions }: { sessions: Session[] }) {
  const [error, setError] = useState<string | null>(null);
  const [busy, startAction] = useTransition();
  const toast = useToast();

  const others = sessions.filter((session) => !session.current).length;

  return (
    <Panel
      id="sessions"
      title="Sessions"
      description="Ending one signs that device out on its next request, not when its token expires."
      actions={
        others > 0 ? (
          <Button
            variant="destructive"
            size="sm"
            disabled={busy}
            onClick={() =>
              startAction(async () => {
                const result = await endOtherSessions();

                setError(result.error);

                if (result.error === null) {
                  toast({ tone: "removed", message: `Ended ${others} other ${others === 1 ? "session" : "sessions"}.` });
                }
              })
            }
          >
            End the other {others === 1 ? "session" : `${others} sessions`}
          </Button>
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

      <DataTable caption="Sessions that can act as you">
        <THead>
          <Tr>
            <Th>Client</Th>
            <Th>Address</Th>
            <Th>Last used</Th>
            <Th>Signed in</Th>
            <Th width="w-28" align="right">
              Action
            </Th>
          </Tr>
        </THead>
        <TBody>
          {sessions.map((session) => (
            <Tr key={session.id}>
              <Td>
                <div className="flex items-center gap-2">
                  {session.current && <Badge tone="info">this device</Badge>}
                  {/* The raw agent string, not a guess at a device name:
                      parsing them is a losing game, and "Chrome on a Mac"
                      derived wrongly is worse than the string somebody can read
                      themselves. */}
                  <span className="truncate font-mono text-micro text-n-500">
                    {session.user_agent ?? "unknown client"}
                  </span>
                </div>
              </Td>
              <Td muted>{session.ip_address ?? "no address"}</Td>
              <Td muted>{session.last_used}</Td>
              <Td muted>{session.started}</Td>
              <Td align="right">
                <Button
                  variant="destructive"
                  size="sm"
                  disabled={busy}
                  onClick={() =>
                    startAction(async () => {
                      const result = await endSession(session.id);

                      setError(result.error);

                      // The device that just lost its session is not this one,
                      // unless it is — and then the sign-out is the
                      // confirmation (ADR 0025).
                      if (result.error === null && !session.current) {
                        toast({ tone: "removed", message: "That device is signed out." });
                      }
                    })
                  }
                >
                  {session.current ? "Sign out here" : "End"}
                </Button>
              </Td>
            </Tr>
          ))}
        </TBody>
      </DataTable>
    </Panel>
  );
}

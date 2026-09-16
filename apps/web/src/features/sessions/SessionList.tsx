"use client";

import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
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

  const others = sessions.filter((session) => !session.current).length;

  return (
    <div className="space-y-3">
      {error && (
        <p
          role="alert"
          className="border border-s-danger/40 px-3 py-2 text-body-sm text-s-danger rounded-md"
        >
          {error}
        </p>
      )}

      <ul className="divide-y divide-n-100 border-y border-n-100">
        {sessions.map((session) => (
          <li key={session.id} className="flex flex-wrap items-baseline gap-x-4 gap-y-1 py-3">
            <span className="min-w-0 flex-1">
              <span className="text-body-sm text-n-900">
                {session.current ? "This device" : "Another device"}
              </span>{" "}
              <span className="text-body-sm text-n-500">{session.ip_address ?? "no address"}</span>
              {/* The raw agent string, not a guess at a device name: parsing
                  them is a losing game, and "Chrome on a Mac" derived wrongly is
                  worse than the string somebody can read themselves. */}
              <span className="mt-0.5 block break-words font-mono text-micro text-n-500">
                {session.user_agent ?? "unknown client"}
              </span>
            </span>

            <span className="w-56 shrink-0 text-body-sm text-n-500">
              last used {session.last_used}
              <span className="block text-micro">signed in {session.started}</span>
            </span>

            <Button
              variant="ghost"
              size="sm"
              disabled={busy}
              onClick={() =>
                startAction(async () => {
                  const result = await endSession(session.id);

                  setError(result.error);
                })
              }
            >
              {session.current ? "Sign out here" : "End"}
            </Button>
          </li>
        ))}
      </ul>

      {others > 0 && (
        <Button
          variant="secondary"
          size="sm"
          disabled={busy}
          onClick={() =>
            startAction(async () => {
              const result = await endOtherSessions();

              setError(result.error);
            })
          }
        >
          End the other {others === 1 ? "session" : `${others} sessions`}
        </Button>
      )}
    </div>
  );
}

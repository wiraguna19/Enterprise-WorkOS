"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { realtime } from "@/lib/echo";

/**
 * Listen to a private channel and refresh the server-rendered page (docs/07 §8).
 *
 * The event carries ids, never content: the handler asks Next to re-render from
 * the API, which applies visibility. Trusting a payload would make the socket
 * the thing that decides what a viewer may read, and it is not — it only says
 * that something changed.
 *
 * Refreshing rather than patching client state also means there is exactly one
 * description of what the page shows. A socket that mutated a local cache would
 * be a second one, and the two would disagree the first time an event was
 * missed — which happens on every reconnect.
 *
 * Does nothing when real-time is unconfigured, which is the supported default.
 */
export function useChannel(channel: string | null, events: string[]) {
  const router = useRouter();

  useEffect(() => {
    const echo = realtime();

    if (echo === null || channel === null) return;

    const subscription = echo.private(channel);

    for (const event of events) {
      subscription.listen(`.${event}`, () => router.refresh());
    }

    return () => {
      // Left, not just unbound: an abandoned subscription keeps receiving on a
      // channel nobody is rendering, and on a long session that accumulates
      // into a page refreshing for reasons it cannot see.
      echo.leave(channel);
    };
    // `events` is a literal at every call site; joining it keeps the effect from
    // re-subscribing on every render without asking callers to memoize.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [channel, events.join(","), router]);
}

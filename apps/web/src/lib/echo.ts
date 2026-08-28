"use client";

import Echo from "laravel-echo";
import Pusher from "pusher-js";

/**
 * The socket client (docs/07 §8).
 *
 * Returns null when real-time is not configured, and that is a first-class
 * outcome rather than an error: a deployment without a socket server is
 * supported, and every screen is correct when polled. Callers treat null as
 * "no live updates" and change nothing else.
 *
 * A module-level singleton because a WebSocket is exactly the kind of resource
 * that must not be created per component — two connections would mean every
 * event handled twice.
 */

type EchoClient = Echo<"reverb">;

let client: EchoClient | null = null;
let attempted = false;

export function realtime(): EchoClient | null {
  if (attempted) return client;

  attempted = true;

  const key = process.env.NEXT_PUBLIC_REVERB_KEY;
  const host = process.env.NEXT_PUBLIC_REVERB_HOST;

  if (!key || !host) return null;

  // laravel-echo reads Pusher off the global in some builds; setting it is the
  // documented arrangement and is harmless when it does not.
  (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

  client = new Echo({
    broadcaster: "reverb",
    key,
    wsHost: host,
    wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 443),
    wssPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 443),
    forceTLS: (process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "https") === "https",
    enabledTransports: ["ws", "wss"],
    // Not the API's own endpoint: the token is in an HttpOnly cookie, so the
    // BFF attaches it (docs/06 §1).
    authEndpoint: "/api/broadcasting/auth",
  });

  return client;
}

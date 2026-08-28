"use client";

import { useChannel } from "./useChannel";

/**
 * The one subscription every authenticated page has: your own stream
 * (docs/07 §8).
 *
 * Mounted in the shell so the inbox badge and My Work counts are current
 * wherever you are. It renders nothing — the shell already renders the numbers,
 * and this only tells it when to ask again.
 */
export function RealtimeProvider({
  organizationId,
  userId,
}: {
  organizationId: string;
  userId: string;
}) {
  useChannel(`org.${organizationId}.user.${userId}`, ["notification.created"]);

  return null;
}

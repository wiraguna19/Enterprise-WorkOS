"use client";

import { useChannel } from "./useChannel";

/**
 * Live updates for one work item (docs/07 §8).
 *
 * Comments and status changes made by other people, on the page that shows
 * them. Renders nothing: the page is server-rendered and correct, and this only
 * says when to ask again.
 *
 * Presence — "someone else is looking at this" — has a channel and an
 * authorization rule already, and is deliberately not wired up here: the
 * indicator is worth building once there is a design for it, and a subscription
 * with no UI is a connection nobody can see the value of.
 */
export function WorkItemChannel({
  organizationId,
  workItemId,
}: {
  organizationId: string;
  workItemId: string;
}) {
  useChannel(`org.${organizationId}.work-item.${workItemId}`, [
    "comment.created",
    "work-item.status_changed",
  ]);

  return null;
}

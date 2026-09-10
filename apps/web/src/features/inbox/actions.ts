"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";

export type DecisionState = { error: string | null; requestId?: string };

/**
 * Record a decision on an approval.
 *
 * A Server Action rather than a browser fetch, for the same reason login is
 * one: the session token lives in an HttpOnly cookie and is attached
 * server-side, so the browser never holds a bearer token (docs/06 §1). It also
 * means the queue re-renders from the API's own answer instead of the client
 * guessing what the row should look like now.
 *
 * The comment rule is NOT enforced here. It is enforced by the API
 * (`required_unless:decision,approved`) and by a CHECK constraint on
 * approval_decisions. This action surfaces the refusal; it does not duplicate
 * the rule, because a copy of a rule is a copy that drifts.
 */
export async function decide(
  approvalId: string,
  decision: "approved" | "changes_requested" | "rejected",
  comment: string,
): Promise<DecisionState> {
  try {
    await api(`/approvals/${approvalId}/decide`, {
      method: "POST",
      body: { decision, comment },
    });
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return { error: error.error.message, requestId: error.error.request_id };
    }

    return { error: "We could not reach the server. Please try again." };
  }

  // Both sides of the queue change: the reviewer's list loses a row, and the
  // work item's status and history gain one.
  revalidatePath("/inbox");

  // And the approval's own page, which is where the decision that was just
  // made has to appear. Deciding FROM that page and being shown the state
  // before the decision is the same bug as a queue that does not empty.
  revalidatePath(`/approvals/${approvalId}`);

  return { error: null };
}

/**
 * Mark notifications read.
 *
 * `ids` omitted means everything unread, which is what the API's own contract
 * says (`ids` is `sometimes`) — the client does not enumerate a list it would
 * then have to keep in step with what the server considers unread.
 *
 * The badge is the reason this exists. `unread-count` is read on every page
 * load and, until now, nothing had ever called this endpoint: the number could
 * only ever go up. A counter that cannot reach zero is a counter people stop
 * reading, and then stop believing.
 */
export async function markRead(ids?: string[]): Promise<{ error: string | null }> {
  try {
    await api("/notifications/read", {
      method: "POST",
      body: ids === undefined ? {} : { ids },
    });
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return { error: error.error.message };
    }

    return { error: "We could not reach the server. Please try again." };
  }

  // The badge lives in the shell layout, not on this page, so revalidating
  // `/inbox` alone would leave the number stale on every other screen. The
  // layout is what re-reads `unread-count`.
  revalidatePath("/", "layout");

  return { error: null };
}

/**
 * Take back your own submission.
 *
 * The last of the three undo paths that had no way in. `approvals/{id}/withdraw`
 * has existed since Phase 4 and the resource has been sending
 * `permissions.withdraw` to a client that read only `permissions.decide` — the
 * server answering a question nobody asked.
 *
 * The API decides who may: only the requester, and only while the approval is
 * still pending. Neither rule is repeated here. What this does add is the
 * refusal's own words when somebody withdraws a submission a reviewer has just
 * decided — a race two people can lose in a shared queue.
 */
export async function withdraw(approvalId: string): Promise<DecisionState> {
  try {
    await api(`/approvals/${approvalId}/withdraw`, { method: "POST" });
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return { error: error.error.message, requestId: error.error.request_id };
    }

    return { error: "We could not reach the server. Please try again." };
  }

  // Both sides: the requester's "waiting on others" loses a row, and the
  // reviewer's queue loses the same one.
  revalidatePath("/inbox");
  revalidatePath(`/approvals/${approvalId}`);
  revalidatePath("/", "layout");

  return { error: null };
}

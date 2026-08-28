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

  return { error: null };
}

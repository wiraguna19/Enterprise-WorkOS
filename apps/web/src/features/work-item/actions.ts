"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";

export type ActionState = { error: string | null; requestId?: string };

/**
 * Move a work item along the workflow.
 *
 * This did not exist. The status picker and the primary action button were both
 * rendered from the workflow graph, correctly, and both were inert: clicking a
 * move closed the menu and changed nothing. The transition endpoint has existed
 * since Phase 3 and every test drives it directly, so the API was proven and
 * the button was decoration — the same shape as the sign-out button in Phase 5
 * and the progress bar in Phase 2.
 *
 * A Server Action rather than a browser fetch, for the reason login is one: the
 * session token lives in an HttpOnly cookie and is attached server-side, so the
 * browser never holds a bearer token (docs/06 §1).
 *
 * `to_state_id`, never a label or a category. The graph decides what a move is;
 * the client names the destination and the API re-checks that the edge exists,
 * that this person may take it, and whether it needs a comment (docs/02 §7).
 * None of those rules are repeated here — a copy of a rule is a copy that
 * drifts.
 */
export async function transitionTo(
  reference: string,
  toStateId: string,
  comment?: string,
): Promise<ActionState> {
  try {
    await api(`/work-items/${reference}/transition`, {
      method: "POST",
      body: comment === undefined || comment.trim() === ""
        ? { to_state_id: toStateId }
        : { to_state_id: toStateId, comment },
    });
  } catch (error) {
    return failure(error);
  }

  // Three places change: the item itself, the lists it appears in, and — when
  // the move opens an approval — the reviewer's queue. Revalidating the item
  // alone leaves My Work showing a status the item no longer has.
  revalidatePath(`/work/${reference}`);
  revalidatePath("/my-work");
  revalidatePath("/inbox");
  revalidatePath("/");

  return { error: null };
}

/**
 * Acknowledge work assigned to you.
 *
 * Needs no permission, only identity: the API route is deliberately outside the
 * permission middleware, because accepting work assigned to you is not an
 * ability an administrator grants.
 */
export async function acceptAssignment(reference: string): Promise<ActionState> {
  try {
    await api(`/work-items/${reference}/accept`, { method: "POST" });
  } catch (error) {
    return failure(error);
  }

  revalidatePath(`/work/${reference}`);
  revalidatePath("/my-work");
  revalidatePath("/");

  return { error: null };
}

function failure(error: unknown): ActionState {
  if (error instanceof ApiRequestError) {
    // The server's own message, and its request id: "why can't I do this" is
    // answered by the rule that refused, not by a generic apology.
    return { error: error.error.message, requestId: error.error.request_id };
  }

  return { error: "We could not reach the server. Please try again." };
}

/**
 * Post a comment.
 *
 * The composer had no action at all: a textarea, a Send button, and a bare
 * `<form>` — so submitting it navigated the page back to itself and threw the
 * text away. Third time this shape has appeared (the sign-out button, the
 * status picker, now this), and the first one the reachability guard could not
 * see: `POST /work-items/{reference}/comments` has the same path as the GET the
 * page already makes, so the grep found a caller and asked no further.
 *
 * `parent_id` is not sent. Replies exist in the API and there is no thread UI
 * yet; sending a field the interface cannot set is how a half-built feature
 * starts pretending.
 */
export async function postComment(reference: string, body: string): Promise<ActionState> {
  const text = body.trim();

  // The only rule enforced here, and only because an empty POST is a round trip
  // that can only fail. Length, mentions and markdown are the API's business.
  if (text === "") {
    return { error: "Write something first." };
  }

  try {
    await api(`/work-items/${reference}/comments`, {
      method: "POST",
      body: { body: text },
    });
  } catch (error) {
    return failure(error);
  }

  // A comment can @mention someone, which sends a notification: the inbox and
  // the badge change too, not just this page.
  revalidatePath(`/work/${reference}`);
  revalidatePath("/", "layout");

  return { error: null };
}

/**
 * Edit your own comment.
 *
 * Authorship is not re-checked here — the API refuses anyone but the author
 * with a 403 naming the rule, and a copy of a rule is a copy that drifts. The
 * interface only decides who is OFFERED the control, which is a different
 * question from who is allowed to use it.
 */
export async function editComment(
  reference: string,
  commentId: string,
  body: string,
): Promise<ActionState> {
  const text = body.trim();

  if (text === "") {
    return { error: "A comment cannot be emptied. Say something else instead." };
  }

  try {
    await api(`/comments/${commentId}`, { method: "PATCH", body: { body: text } });
  } catch (error) {
    return failure(error);
  }

  revalidatePath(`/work/${reference}`);

  return { error: null };
}

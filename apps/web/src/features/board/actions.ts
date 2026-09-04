"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";
import type { Transition } from "@/features/work-item/types";

export type BoardActionState = { error: string | null; requestId?: string };

/**
 * The moves this card may make, asked at the moment it is picked up.
 *
 * The board payload does not carry them — it is one query for every card on the
 * screen, and computing the workflow graph for each would undo that. So they
 * are fetched for the ONE card being dragged, from the same endpoint the status
 * menu uses, which is also the query the API runs to authorise the write. The
 * board therefore cannot offer a destination the server will refuse.
 *
 * A Server Action rather than a browser fetch, for the reason every mutation
 * here is one: the session token is in an HttpOnly cookie and never reaches the
 * browser (docs/06 §1).
 */
export async function legalMoves(reference: string): Promise<Transition[]> {
  try {
    const { data } = await api<{ current: { id: string }; transitions: Transition[] }>(
      `/work-items/${reference}/available-transitions`,
    );

    return data.transitions;
  } catch {
    // An empty list means "no destination is offered", which is the safe
    // reading: the drop is refused locally and the card stays where it is.
    return [];
  }
}

/**
 * Dropping a card into a different column.
 *
 * This is a transition and posts to the transition endpoint, not to `/move`
 * with a `to_state_id` (ADR 0012 §2). `move` would reach the same behaviour
 * through a weaker permission, and two endpoints reaching one behaviour must
 * ask the same question of the caller.
 *
 * The consequence, accepted: the card lands where its new column's ordering
 * puts it and carries no position. Placing it precisely is a second drag.
 */
export async function dropOnColumn(
  reference: string,
  projectKey: string,
  toStateId: string,
  comment?: string,
): Promise<BoardActionState> {
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

  revalidateBoard(projectKey, reference);

  return { error: null };
}

/**
 * Reordering within one column.
 *
 * `before_id` and `after_id` are the new neighbours; the server picks a
 * fractional position between them and writes ONE row (docs/03 §3). No state is
 * sent, because nothing about the card's state is changing — sending its
 * current state would be true and would still ask the API a question about a
 * transition that is not happening.
 */
export async function reorderInColumn(
  reference: string,
  projectKey: string,
  beforeId: string | null,
  afterId: string | null,
): Promise<BoardActionState> {
  try {
    await api(`/work-items/${reference}/move`, {
      method: "POST",
      body: { before_id: beforeId, after_id: afterId },
    });
  } catch (error) {
    return failure(error);
  }

  revalidateBoard(projectKey, reference);

  return { error: null };
}

function revalidateBoard(projectKey: string, reference: string): void {
  // The board is what moved, but a transition also changes the item's own page,
  // the lists it appears in, and — when the move opens an approval — a
  // reviewer's queue. Revalidating the board alone leaves My Work showing a
  // status the item no longer has.
  revalidatePath(`/projects/${projectKey}/board`);
  revalidatePath(`/projects/${projectKey}/overview`);
  revalidatePath(`/work/${reference}`);
  revalidatePath("/my-work");
  revalidatePath("/inbox");
  revalidatePath("/");
}

function failure(error: unknown): BoardActionState {
  if (error instanceof ApiRequestError) {
    return { error: error.error.message, requestId: error.error.request_id };
  }

  return { error: "We could not reach the server. Please try again." };
}

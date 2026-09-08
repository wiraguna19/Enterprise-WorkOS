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

/**
 * What the create form sends. Every field but the title is optional, and
 * `undefined` means "not sent" rather than "cleared": the API applies its own
 * defaults for type, priority and state, and a client that posts its idea of
 * them is a second copy of a rule that will drift.
 */
export type NewWorkItem = {
  title: string;
  description?: string;
  type?: string;
  project_id?: string | null;
  priority?: string;
  start_date?: string | null;
  due_at?: string | null;
  estimate_hours?: string;
  assignee_id?: string | null;
  reviewer_id?: string | null;
};

/**
 * Create a work item.
 *
 * `POST /work-items` has existed since Phase 3 and nothing had ever called it:
 * every work item in this product came from the seed or from curl. The
 * reachability guard could not see it, because the same path is listed on My
 * Work and in search — a write hiding behind its own read, the fourth variant
 * of this project's oldest defect.
 *
 * **Assignment happens here, not afterwards.** The API accepts `assignee_id`
 * and `reviewer_id` on create for the reason docs/08 §4 gives: making people
 * create a thing and then assign it is the most common unnecessary click in
 * tools of this kind — and an item created with nobody on it is a row that
 * waits for someone to notice it.
 *
 * Empty strings are dropped rather than sent. A `<select>` with nothing chosen
 * yields `""`, and `""` is not a uuid, not a date, and not "no value" to a
 * validator — it is a 422 with a message about a field the person never
 * touched.
 */
export async function createWorkItem(
  input: NewWorkItem,
  /** The project page this was started from, if it was — see below. */
  projectKey?: string,
): Promise<ActionState & { reference?: string }> {
  const body: Record<string, unknown> = {};

  for (const [field, value] of Object.entries(input)) {
    if (value !== undefined && value !== "") body[field] = value;
  }

  let reference: string;

  try {
    const { data } = await api<{ reference: string }>("/work-items", {
      method: "POST",
      body,
    });

    reference = data.reference;
  } catch (error) {
    return failure(error);
  }

  // Everywhere the new item can already appear. The board and overview are
  // keyed by the project's KEY and the form only knows its id, so the key comes
  // from the page that opened the form — the one case where the caller knows
  // something the payload does not.
  revalidatePath("/my-work");
  revalidatePath("/projects");
  revalidatePath("/");

  if (projectKey !== undefined) {
    revalidatePath(`/projects/${projectKey}/board`);
    revalidatePath(`/projects/${projectKey}/overview`);
  }

  return { error: null, reference };
}

/** What the edit form may change. Status is not here: it moves through /transition. */
export type WorkItemEdit = {
  title?: string;
  description?: string;
  priority?: string;
  start_date?: string | null;
  due_at?: string | null;
  estimate_hours?: string | null;
};

export type EditState = ActionState & {
  /** Set when the item moved on while somebody was editing it. */
  conflict?: { yours: number; current: number };
};

/**
 * Edit a work item.
 *
 * An item could be created and never corrected: `PATCH /work-items/{reference}`
 * has existed since Phase 3 and nothing called it, which made a typo in a title
 * permanent and a moved deadline a job for curl.
 *
 * **Only what changed is sent.** That is PATCH's contract here, and it is also
 * what makes the activity log's diff mean anything (docs/05 §5) — a full
 * document PUT would record every field as touched on every save, and a history
 * where everything changed every time is a history nobody reads.
 *
 * **`lock_version` is always sent.** The API answers 409 with both versions
 * rather than overwriting somebody (docs/03 §8), and this carries that back as
 * a named result instead of a message: a conflict is not a validation error,
 * and the screen owes the person a different sentence and a different next
 * step. `error.code` is what it branches on — stable and machine-readable —
 * never the message, which is localised.
 */
export async function updateWorkItem(
  reference: string,
  changes: WorkItemEdit,
  lockVersion: number,
): Promise<EditState> {
  if (Object.keys(changes).length === 0) {
    return { error: null };
  }

  try {
    await api(`/work-items/${reference}`, {
      method: "PATCH",
      body: { ...changes, lock_version: lockVersion },
    });
  } catch (error) {
    if (error instanceof ApiRequestError && error.error.code === "concurrency.conflict") {
      const details = error.error.details ?? {};

      return {
        error: error.error.message,
        requestId: error.error.request_id,
        conflict: {
          yours: Number(details.your_version ?? lockVersion),
          current: Number(details.current_version ?? lockVersion),
        },
      };
    }

    return failure(error);
  }

  revalidatePath(`/work/${reference}`);
  revalidatePath("/my-work");
  revalidatePath("/");

  return { error: null };
}

/**
 * Delete a work item.
 *
 * Soft, on the server: the row and its trail survive, because restoring work
 * somebody removed by mistake is a real support request (docs/03 §0). The
 * interface says so rather than promising an erasure that does not happen —
 * and does not promise a restore button either, because there is not one.
 */
export async function deleteWorkItem(reference: string): Promise<ActionState> {
  try {
    await api(`/work-items/${reference}`, { method: "DELETE" });
  } catch (error) {
    return failure(error);
  }

  // Not the item's own page: it is gone, and revalidating a route the caller is
  // about to leave for good is how a deleted item flashes back into view.
  revalidatePath("/my-work");
  revalidatePath("/projects");
  revalidatePath("/");

  return { error: null };
}

/**
 * Give a role on this item to somebody.
 *
 * `POST /work-items/{reference}/assign` shipped in Phase 3 and nothing called
 * it: the create form could name an assignee, and after that the only way to
 * change one was the API. It hid from the reachability guard behind
 * `/work-items/${reference}/assignments` — the history endpoint a page does
 * fetch — because a prefix used to count as a caller.
 *
 * The API decides what happens to whoever held the role: reassigning is one
 * call, not "unassign then assign", so there is no window in which the item
 * belongs to nobody and no pair of activity rows to read as two decisions.
 * Sending both would also be racing another person's edit at half speed.
 *
 * A `reason` is not collected. The activity log records who did it and when,
 * and asking for a sentence before every reassignment is how people learn to
 * type "." into a box.
 */
export async function assignTo(
  reference: string,
  membershipId: string,
  role: "assignee" | "reviewer",
): Promise<ActionState> {
  try {
    await api(`/work-items/${reference}/assign`, {
      method: "POST",
      body: { membership_id: membershipId, role },
    });
  } catch (error) {
    return failure(error);
  }

  // My Work is somebody ELSE's list now, or was until a moment ago — both
  // people's lists change, and the badge with them.
  revalidatePath(`/work/${reference}`);
  revalidatePath("/my-work");
  revalidatePath("/", "layout");

  return { error: null };
}

/**
 * Take a role away without giving it to anyone.
 *
 * The second half of assigning, and one of the undo paths that had no way in.
 * The row is closed rather than deleted — `unassigned_at` with a reason — so
 * the history still says who held it and for how long.
 *
 * Deliberately NOT offered as "reassign to nobody" inside the picker: leaving
 * an item unowned is a decision, and it should cost a different click from
 * choosing a colleague.
 */
export async function unassign(reference: string, assignmentId: string): Promise<ActionState> {
  try {
    await api(`/work-items/${reference}/assignees/${assignmentId}`, { method: "DELETE" });
  } catch (error) {
    return failure(error);
  }

  revalidatePath(`/work/${reference}`);
  revalidatePath("/my-work");
  revalidatePath("/", "layout");

  return { error: null };
}

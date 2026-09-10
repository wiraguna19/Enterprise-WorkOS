"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";

export type RecurrenceResult = { error: string | null; requestId?: string; id?: string };

export type NewRecurrence = {
  rrule: string;
  ends_at?: string | null;
  template: {
    title: string;
    type?: string;
    project_id?: string | null;
    priority?: string;
    description?: string;
    estimate_hours?: number;
    due_in_days?: number;
    assignee_id?: string | null;
  };
};

function failure(error: unknown): RecurrenceResult {
  if (error instanceof ApiRequestError) {
    return { error: error.error.message, requestId: error.error.request_id };
  }

  return { error: "We could not reach the server. Please try again." };
}

/**
 * A standing instruction to create work.
 *
 * Phase 5 shipped RRULE recurrence end to end — the rule, the materializer, the
 * scheduled command, the link from every item back to the rule that made it —
 * and no way to create one. Recurring work has existed in this product only in
 * the seed and through curl.
 *
 * The template is sent as a nested object because that is the shape the API
 * validates (`template.title`, `template.due_in_days`), and blank fields are
 * dropped: `""` is not "no value" to a validator, and an empty `<select>` would
 * collect a 422 about a field the person never touched.
 */
export async function createRecurrence(input: NewRecurrence): Promise<RecurrenceResult> {
  const template: Record<string, unknown> = {};

  for (const [field, value] of Object.entries(input.template)) {
    if (value !== undefined && value !== "" && !Number.isNaN(value)) template[field] = value;
  }

  const body: Record<string, unknown> = { rrule: input.rrule, template };

  if (input.ends_at) body.ends_at = input.ends_at;

  let id: string;

  try {
    const { data } = await api<{ id: string }>("/recurrences", { method: "POST", body });

    id = data.id;
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/recurring");

  return { error: null, id };
}

/**
 * Stop a recurrence. Not delete it.
 *
 * The endpoint is a DELETE and deactivates the row, and this action is named
 * for what it DOES rather than for the verb it sends: the work already created
 * carries `recurrence_id`, and that link is how anyone answers "where did this
 * come from" months later. A control called "Delete" would promise an erasure
 * the API deliberately does not perform.
 */
export async function stopRecurrence(id: string): Promise<RecurrenceResult> {
  try {
    await api(`/recurrences/${id}`, { method: "DELETE" });
  } catch (error) {
    return failure(error);
  }

  revalidatePath("/recurring");

  return { error: null };
}

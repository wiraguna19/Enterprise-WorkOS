"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError, describeApiError } from "@/lib/api";

export type StructureResult = { error: string | null; requestId?: string; id?: string };

/**
 * Creating, renaming and moving a department; creating a team.
 *
 * Phase 2 built all four and the product called none of them. The LIST has been
 * read since the New project form shipped, which is why the reachability guard
 * exempted these per VERB rather than by path — a whole-path exemption would
 * have stopped watching a read the product depends on.
 *
 * Every one of these is a Server Action for the reason all mutations here are
 * (ADR 0012): the session token lives in an HttpOnly cookie and never reaches
 * the browser.
 */

/** Fields the caller left blank are not sent — `""` is not "no value" to a validator. */
function bodyOf(input: Record<string, unknown>): Record<string, unknown> {
  const body: Record<string, unknown> = {};

  for (const [field, value] of Object.entries(input)) {
    if (value !== undefined && value !== "") body[field] = value;
  }

  return body;
}

function failure(error: unknown): StructureResult {
  if (error instanceof ApiRequestError) {
    return { error: error.error.message, requestId: error.error.request_id };
  }

  return { error: "We could not reach the server. Please try again." };
}

/**
 * The structure is read by more than the screen that edits it: the New project
 * form offers departments, and the shell's team list is in the layout. So the
 * layout is revalidated too — a team created here that does not appear in the
 * sidebar until a hard refresh reads as a failed creation.
 */
function revalidateStructure(): void {
  revalidatePath("/departments");
  revalidatePath("/teams");
  revalidatePath("/projects/new");
  revalidatePath("/", "layout");
}

export async function createDepartment(input: {
  name: string;
  code: string;
  parent_id?: string | null;
}): Promise<StructureResult> {
  const body = bodyOf(input);

  // Upper-cased here, not validated here. The API's rule is
  // `/^[A-Za-z0-9-]+$/` and a person typing "eng" means ENG; correcting the
  // case is a kindness, while re-implementing the pattern is a second copy of
  // a rule that will drift from the one that decides.
  body.code = String(input.code).trim().toUpperCase();

  let id: string;

  try {
    const { data } = await api<{ id: string }>("/departments", { method: "POST", body });

    id = data.id;
  } catch (error) {
    return failure(error);
  }

  revalidateStructure();

  return { error: null, id };
}

export async function renameDepartment(id: string, name: string): Promise<StructureResult> {
  try {
    await api(`/departments/${id}`, { method: "PATCH", body: { name } });
  } catch (error) {
    return failure(error);
  }

  revalidateStructure();

  return { error: null };
}

/**
 * Re-parent a department, or make it a root.
 *
 * `parent_id` is `present, nullable` on the API: null is a real answer here —
 * "no parent" — so this sends the key explicitly rather than through `bodyOf`,
 * which drops empty values. Sending nothing would be a different request.
 *
 * Cycles and depth are refused by the domain, inside the transaction that does
 * the move, because they need row locks to be correct under concurrency. This
 * action does not pre-check them: a second opinion computed here would be
 * wrong exactly when two people move departments at once.
 */
export async function moveDepartment(
  id: string,
  parentId: string | null,
): Promise<StructureResult> {
  try {
    await api(`/departments/${id}/move`, {
      method: "POST",
      body: { parent_id: parentId === "" ? null : parentId },
    });
  } catch (error) {
    return failure(error);
  }

  revalidateStructure();

  return { error: null };
}

export async function createTeam(input: {
  name: string;
  key: string;
  department_id?: string | null;
  lead_membership_id?: string | null;
}): Promise<StructureResult> {
  const body = bodyOf(input);

  body.key = String(input.key).trim().toUpperCase();

  let id: string;

  try {
    const { data } = await api<{ id: string }>("/teams", { method: "POST", body });

    id = data.id;
  } catch (error) {
    return failure(error);
  }

  revalidateStructure();

  return { error: null, id };
}

/**
 * How long a session may live here (ADR 0028).
 *
 * The count of shortened sessions comes back from the API rather than being
 * worked out in the browser: only the server knows how many sessions outlived
 * the new window at the moment the change landed, and that number is the
 * difference between a setting and a consequence.
 */
export type SessionPolicyResult = { error: string | null; shortened: number };

export async function setSessionPolicy(
  days: number,
  /** null is a real answer: no idle timeout. */
  idleMinutes: number | null,
): Promise<SessionPolicyResult> {
  try {
    const { data } = await api<{ session_lifetime_days: number; sessions_shortened: number }>(
      "/organization/settings/session-policy",
      {
        method: "PATCH",
        body: { session_lifetime_days: days, idle_timeout_minutes: idleMinutes },
      },
    );

    revalidatePath("/settings/organization");

    return { error: null, shortened: data.sessions_shortened };
  } catch (error) {
    return { error: describeApiError(error).error, shortened: 0 };
  }
}

/**
 * Requiring a second factor of everybody here (ADR 0033).
 *
 * The count that comes back is how many people are now confined to the
 * enrolment screen — not how many were signed out, because nobody is. It is
 * reported for the same reason the shortened-session count is: an
 * administrator should learn the size of what they just did from the product,
 * not from the people it happened to.
 */
export type MfaPolicyResult = { error: string | null; confined: number };

export async function setMfaPolicy(required: boolean): Promise<MfaPolicyResult> {
  try {
    const { data } = await api<{ require_mfa: boolean; people_confined: number }>(
      "/organization/settings/mfa-policy",
      { method: "PATCH", body: { require_mfa: required } },
    );

    revalidatePath("/settings/organization");

    return { error: null, confined: data.people_confined };
  } catch (error) {
    return { error: describeApiError(error).error, confined: 0 };
  }
}

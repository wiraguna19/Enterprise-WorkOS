"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";
import type { Rule } from "./types";

/**
 * Writing a rule.
 *
 * Every mutation is a Server Action with `revalidatePath`, and nothing is
 * optimistic (ADR 0012): a rule that appears switched off in the interface and
 * is still running in the engine is the worst lie this screen could tell.
 */
export type RuleResult = { error: string | null; requestId?: string; id?: string };

export type RuleInput = {
  name: string;
  description: string;
  trigger: string;
  conditions: Record<string, unknown>;
  actions: Array<{ type: string; with: Record<string, unknown> }>;
  is_active?: boolean;
  run_order?: number;
};

function failure(error: unknown): RuleResult {
  if (error instanceof ApiRequestError) {
    // The validator names what it refused — the field it does not recognise,
    // the comparison it cannot make — and those sentences are the whole reason
    // the rule is validated at the door. Surface them rather than the generic
    // envelope message.
    const details = error.error.details as Record<string, string[]> | undefined;
    const refusals = details ? Object.values(details).flat() : [];

    return {
      error: refusals.length > 0 ? refusals.join(" ") : error.error.message,
      requestId: error.error.request_id,
    };
  }

  return { error: "We could not reach the server. Please try again." };
}

function refresh(id?: string): void {
  revalidatePath("/settings/rules");

  if (id) revalidatePath(`/settings/rules/${id}`);
}

export async function createRule(input: RuleInput): Promise<RuleResult> {
  let id: string;

  try {
    const { data } = await api<Rule>("/workflow-rules", { method: "POST", body: input });

    id = data.id;
  } catch (error) {
    return failure(error);
  }

  refresh();

  return { error: null, id };
}

export async function saveRule(id: string, input: Partial<RuleInput>): Promise<RuleResult> {
  try {
    await api<Rule>(`/workflow-rules/${id}`, { method: "PATCH", body: input });
  } catch (error) {
    return failure(error);
  }

  refresh(id);

  return { error: null, id };
}

/**
 * Switching a rule off, and back on.
 *
 * A PATCH carrying only `is_active`, never the whole rule: a form that posts
 * every field turns "switch this off" into a rewrite with whatever the client
 * last read, and would silently revert somebody else's edit from a minute ago.
 *
 * Switching one back on clears its failure count server-side — otherwise a rule
 * somebody has just fixed sits one failure away from being disabled again.
 */
export async function setRuleActive(id: string, active: boolean): Promise<RuleResult> {
  return saveRule(id, { is_active: active });
}

/**
 * Editing the graph work moves through (ADR 0015).
 *
 * The API refuses the destructive edits with 409 and a named reason, and those
 * sentences are surfaced as they are: "12 work items are in this state. Move
 * them first." is the most useful thing this screen can say, and no wording
 * this client invented would be as specific.
 */
export type StateInput = {
  key?: string;
  label?: string;
  category?: string;
  color?: string;
  requires_approval?: boolean;
};

function refreshGraph(): void {
  revalidatePath("/settings/workflows");
}

export async function addState(workflowId: string, input: StateInput): Promise<RuleResult> {
  try {
    await api(`/workflows/${workflowId}/states`, { method: "POST", body: input });
  } catch (error) {
    return failure(error);
  }

  refreshGraph();

  return { error: null };
}

export async function renameState(
  workflowId: string,
  stateId: string,
  label: string,
): Promise<RuleResult> {
  try {
    await api(`/workflows/${workflowId}/states/${stateId}`, {
      method: "PATCH",
      // Only the label. The category and the key are refused by the API, and
      // sending them unchanged would still be asking for something this form
      // has no business asking.
      body: { label },
    });
  } catch (error) {
    return failure(error);
  }

  refreshGraph();

  return { error: null };
}

export async function removeState(workflowId: string, stateId: string): Promise<RuleResult> {
  try {
    await api(`/workflows/${workflowId}/states/${stateId}`, { method: "DELETE" });
  } catch (error) {
    return failure(error);
  }

  refreshGraph();

  return { error: null };
}

export async function addTransition(
  workflowId: string,
  input: {
    from_state_id: string | null;
    to_state_id: string;
    label: string;
    requires_comment: boolean;
  },
): Promise<RuleResult> {
  try {
    await api(`/workflows/${workflowId}/transitions`, { method: "POST", body: input });
  } catch (error) {
    return failure(error);
  }

  refreshGraph();

  return { error: null };
}

export async function removeTransition(
  workflowId: string,
  transitionId: string,
): Promise<RuleResult> {
  try {
    await api(`/workflows/${workflowId}/transitions/${transitionId}`, { method: "DELETE" });
  } catch (error) {
    return failure(error);
  }

  refreshGraph();

  return { error: null };
}

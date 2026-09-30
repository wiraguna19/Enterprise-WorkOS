"use server";

import { revalidatePath } from "next/cache";
import { api, describeApiError } from "@/lib/api";
import type { TemplateFields } from "./templates";

/**
 * Writing a work item template (ADR 0047).
 *
 * The refusals are the product here, as they are for custom fields: a name
 * already taken, a template that fills in nothing, a custom field answer the
 * field would not accept. Each arrives as the API's sentence through
 * `describeApiError`, never as `error.details` printed raw.
 */
export type TemplateResult = { error: string | null };

type TemplateInput = { name: string; purpose: string; fields: TemplateFields };

/** Where a template is written from: the organization's settings, or one project's. */
type Scope = { projectKey?: string };

function refresh(projectKey?: string): void {
  revalidatePath("/settings/templates");
  if (projectKey !== undefined) revalidatePath(`/projects/${projectKey}/settings`);
  revalidatePath("/recurring/new");
  // The picker lives on the create form, which is rendered on the server and
  // would otherwise keep offering a template that was just renamed or deleted.
  revalidatePath("/work/new");
}

export async function createTemplate(input: TemplateInput, scope: Scope = {}): Promise<TemplateResult> {
  try {
    await api("/work-item-templates", {
      method: "POST",
      body: {
        name: input.name,
        purpose: input.purpose,
        fields: input.fields,
        // A project's own template (ADR 0058); absent, the organization's.
        ...(scope.projectKey === undefined ? {} : { project: scope.projectKey }),
      },
    });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh(scope.projectKey);

  return { error: null };
}

/**
 * The whole prefill, every time.
 *
 * The API REPLACES `fields` rather than merging it, because a merge could never
 * remove a key — clearing a template's priority would save and change nothing.
 * So this sends everything the editor shows, the same rule the notification
 * preferences settled on: send the whole thing, not the part that changed.
 */
export async function saveTemplate(
  id: string,
  input: TemplateInput,
  scope: Scope = {},
): Promise<TemplateResult> {
  try {
    await api(`/work-item-templates/${id}`, {
      method: "PATCH",
      body: { name: input.name, purpose: input.purpose, fields: input.fields },
    });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh(scope.projectKey);

  return { error: null };
}

export async function deleteTemplate(id: string, scope: Scope = {}): Promise<TemplateResult> {
  try {
    await api(`/work-item-templates/${id}`, { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refresh(scope.projectKey);

  return { error: null };
}

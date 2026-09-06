"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError } from "@/lib/api";

export type ProjectResult = { error: string | null; requestId?: string; key?: string };

export type NewProject = {
  key: string;
  name: string;
  description?: string;
  department_id?: string | null;
  visibility?: string;
  priority?: string;
  start_date?: string | null;
  end_date?: string | null;
};

/**
 * Create a project.
 *
 * `POST /projects` had been complete since Phase 2 with two buttons pointing at
 * it and neither wired: "New project" in the directory's header, and "Create
 * the first project" in its empty state — the second being the worse of the
 * two, since it is what a brand-new organization sees first.
 *
 * The key is uppercased here rather than validated here. The API's rule is
 * `/^[A-Z][A-Z0-9]{1,11}$/`, and a person typing "eng" means ENG: correcting
 * the case is a kindness, while re-implementing the pattern is a second copy
 * of a rule that will drift from the one that decides. The form states the
 * shape where it is typed; the API refuses what does not match it.
 */
export async function createProject(input: NewProject): Promise<ProjectResult> {
  const body: Record<string, unknown> = {};

  for (const [field, value] of Object.entries(input)) {
    if (value !== undefined && value !== "") body[field] = value;
  }

  body.key = String(input.key).trim().toUpperCase();

  let key: string;

  try {
    const { data } = await api<{ key: string }>("/projects", { method: "POST", body });

    key = data.key;
  } catch (error) {
    if (error instanceof ApiRequestError) {
      return { error: error.error.message, requestId: error.error.request_id };
    }

    return { error: "We could not reach the server. Please try again." };
  }

  // The directory, and the Home that counts projects. The new project's own
  // pages have never been rendered, so there is nothing of theirs to
  // invalidate.
  revalidatePath("/projects");
  revalidatePath("/");

  return { error: null, key };
}

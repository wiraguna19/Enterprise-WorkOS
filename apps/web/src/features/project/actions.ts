"use server";

import { revalidatePath } from "next/cache";
import { api, ApiRequestError, describeApiError } from "@/lib/api";

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

export type ProjectEdit = {
  name?: string;
  description?: string;
  visibility?: string;
  priority?: string;
  status?: string;
  start_date?: string | null;
  end_date?: string | null;
};

export type EditProjectState = {
  error: string | null;
  /** Set when somebody else saved while this form was open. */
  conflict?: { yours: number; current: number };
};

/**
 * Correct a project (ADR 0040).
 *
 * A project could be created and never corrected: `PATCH /projects/{key}` was
 * not a route, while `project.update` was granted to roles and answered by a
 * policy. A typo in a project's name was permanent.
 *
 * Only changed fields travel, and `lock_version` travels with them. On a 409
 * this returns both numbers and nothing offers to force: the other person's
 * edit is not an obstacle.
 */
export async function updateProject(
  key: string,
  changes: ProjectEdit,
  lockVersion: number,
): Promise<EditProjectState> {
  if (Object.keys(changes).length === 0) return { error: null };

  try {
    await api(`/projects/${key}`, {
      method: "PATCH",
      body: { ...changes, lock_version: lockVersion },
    });
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 409) {
      const details = (error.error.details ?? {}) as Record<string, number>;

      return {
        error: error.error.message,
        conflict: { yours: details.your_version ?? lockVersion, current: details.current_version ?? 0 },
      };
    }

    return { error: describeApiError(error).error };
  }

  refreshProject(key);

  return { error: null };
}

/** Take a project off the boards, or bring it back. Never a delete. */
export async function setProjectArchived(key: string, archived: boolean): Promise<ProjectResult> {
  try {
    await api(`/projects/${key}/archive`, { method: "POST", body: { archived } });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refreshProject(key);

  return { error: null };
}

/**
 * Everywhere a project's name, status or presence is rendered.
 *
 * The directory and Home list projects; the project's own views print its name
 * in their header and breadcrumb. Revalidating only the page that was saved is
 * how a renamed project keeps its old name in the sidebar until something else
 * happens to refresh it.
 */
function refreshProject(key: string): void {
  revalidatePath("/projects");
  revalidatePath("/");
  revalidatePath(`/projects/${key}/overview`);
  revalidatePath(`/projects/${key}/board`);
  revalidatePath(`/projects/${key}/settings`);
}

export type ProjectMember = {
  id: string;
  subject: "person" | "team";
  membership_id: string | null;
  team_id: string | null;
  name: string | null;
  avatar_url: string | null;
  role: string;
  added_at: string;
};

/**
 * Give a person or a team access to a project (ADR 0041).
 *
 * `project_members` has decided project visibility since Phase 2 and had no
 * write path: a project created as PRIVATE was visible to its creator and to
 * nobody else, for ever.
 */
export async function addProjectMember(
  key: string,
  input: { membership_id?: string; team_id?: string; role: string },
): Promise<ProjectResult> {
  try {
    await api(`/projects/${key}/members`, { method: "POST", body: input });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refreshProject(key);

  return { error: null };
}

export async function setProjectMemberRole(
  key: string,
  memberId: string,
  role: string,
): Promise<ProjectResult> {
  try {
    await api(`/projects/${key}/members/${memberId}`, { method: "PATCH", body: { role } });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refreshProject(key);

  return { error: null };
}

export async function removeProjectMember(key: string, memberId: string): Promise<ProjectResult> {
  try {
    await api(`/projects/${key}/members/${memberId}`, { method: "DELETE" });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  refreshProject(key);

  return { error: null };
}

/**
 * Keep a project in your own sidebar, or stop (ADR 0044).
 *
 * Idempotent in both directions — the unique index decides, not a check here —
 * so a double click is not an error anybody should be shown.
 */
export async function setProjectPinned(key: string, pinned: boolean): Promise<ProjectResult> {
  try {
    await api(`/projects/${key}/pin`, { method: "POST", body: { pinned } });
  } catch (error) {
    return { error: describeApiError(error).error };
  }

  // The LAYOUT renders the sidebar, and a path revalidation does not reach it
  // from here — the caller refreshes. This still revalidates the directory so
  // the star it just pressed is correct on the next render.
  revalidatePath("/projects");

  return { error: null };
}

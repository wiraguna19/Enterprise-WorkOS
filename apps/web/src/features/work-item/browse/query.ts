import type { CustomFieldAnswer } from "@/features/custom-fields/types";

/**
 * The browse screen's URL, and the API query it becomes (ADR 0039, ADR 0038).
 *
 * One module rather than logic split between the page and the filter bar,
 * because the URL is the single source of truth for this screen: the server
 * component reads it to fetch, the client component writes it to filter, and
 * two copies of "what does `?late=1` mean" would drift the way two lists
 * always do.
 *
 * **The URL is the state.** No client data layer (ADR 0012), so a filter is a
 * navigation: it survives a reload, it can be linked to somebody else, and the
 * back button undoes it. That is also what makes this screen the caller for
 * `filter[cf_<key>]`, which until now had no screen at all.
 */
export type BrowseParams = {
  category?: string;
  priority?: string;
  project?: string;
  assignee?: string;
  late?: string;
  unassigned?: string;
  sort?: string;
  cursor?: string;
  /** `cf_<key>=value`, kept verbatim so a declared field needs no code here. */
  [key: string]: string | undefined;
};

export const CATEGORIES = ["backlog", "todo", "in_progress", "in_review", "blocked", "done", "cancelled"] as const;

export const PRIORITIES = ["low", "medium", "high", "urgent"] as const;

export const SORTS: Array<{ value: string; label: string }> = [
  { value: "position", label: "Board order" },
  { value: "due_at", label: "Due soonest" },
  { value: "-due_at", label: "Due latest" },
  { value: "-updated_at", label: "Recently changed" },
  { value: "-created_at", label: "Newest" },
  { value: "priority", label: "Priority" },
];

/** Is this parameter one of an organization's own fields? */
export function isCustomKey(key: string): boolean {
  return key.startsWith("cf_");
}

/**
 * The API query string for a set of browse parameters.
 *
 * Empty values are DROPPED, never sent as `filter[x]=`. An empty string is not
 * "no value" to a validator — this codebase has shipped that bug before — and
 * since ADR 0039 an unknown or malformed filter is a 422 rather than a silence,
 * so sending one is now a visible error instead of a wrong list.
 */
export function apiQuery(params: BrowseParams, limit = 50): string {
  const query = new URLSearchParams();

  const add = (key: string, value: string | undefined): void => {
    if (value !== undefined && value !== "") query.set(key, value);
  };

  add("filter[state_category]", params.category);
  add("filter[priority]", params.priority);
  add("filter[project_id]", params.project);
  add("filter[assignee_id]", params.assignee);
  if (params.late === "1") query.set("filter[overdue]", "1");
  if (params.unassigned === "1") query.set("filter[unassigned]", "1");

  for (const [key, value] of Object.entries(params)) {
    if (isCustomKey(key)) add(`filter[${key}]`, value);
  }

  add("sort", params.sort);
  add("cursor", params.cursor);
  query.set("limit", String(limit));

  return query.toString();
}

/** How many filters are on, so the screen can say so and offer to clear them. */
export function activeCount(params: BrowseParams): number {
  return Object.entries(params).filter(
    ([key, value]) =>
      value !== undefined &&
      value !== "" &&
      key !== "sort" &&
      key !== "cursor",
  ).length;
}

/** A blank value per declared field, so the bar's inputs are controlled. */
export function customValues(
  fields: CustomFieldAnswer[],
  params: BrowseParams,
): Record<string, string> {
  return Object.fromEntries(fields.map((field) => [field.key, params[`cf_${field.key}`] ?? ""]));
}

import Link from "next/link";
import { Panel } from "@/components/ui/Panel";
import type { WorkItemTemplate } from "../templates";

/**
 * The templates, as links (ADR 0047).
 *
 * Links and not a select with a handler: choosing a template GOES somewhere —
 * the same page with `?template=ID` — so it is an anchor, it survives a reload,
 * and it can be pasted to a colleague ("use this one"). Whatever else the page
 * was opened with (`keep`, e.g. the project) travels with it.
 *
 * Shared by the two forms that start from a template: a new work item, and a
 * new recurrence — which is a work item that keeps being made.
 */
export function TemplatePicker({
  basePath,
  templates,
  chosenId,
  keep = {},
  projectKey,
  missing,
}: {
  /** The page the links lead back to, e.g. `/work/new`. */
  basePath: string;
  templates: WorkItemTemplate[] | null;
  chosenId?: string;
  /** Query parameters to carry into every link. */
  keep?: Record<string, string>;
  /**
   * When the form is already about one project: offer the organization's
   * templates and THAT project's, not every project's (ADR 0058).
   */
  projectKey?: string;
  missing: boolean;
}) {
  if (templates === null) {
    return (
      <p role="status" className="text-caption text-n-500">
        Templates could not be loaded. You can still fill in the form by hand.
      </p>
    );
  }

  // No section at all when there is nothing to choose. The settings screen is
  // where templates are written, and pointing at it from here would be a link
  // most people who see it cannot follow.
  const offered = templates.filter(
    (template) => template.project === null || projectKey === undefined || template.project.key === projectKey,
  );

  if (offered.length === 0) return null;

  const href = (template?: WorkItemTemplate): string => {
    const query = new URLSearchParams(keep);

    if (template !== undefined) query.set("template", template.id);
    // A project's template opens the form ON that project: it was written for
    // its work, and a create form that then asked "which project?" would be
    // asking a question the template already answered.
    if (template?.project) query.set("project", template.project.key);

    const qs = query.toString();

    return qs === "" ? basePath : `${basePath}?${qs}`;
  };

  return (
    <Panel
      id="start-from"
      title="Start from"
      description={
        missing
          ? "That template no longer exists. Pick another, or start blank."
          : "A template fills in the form below. You can change anything before creating."
      }
      bleed
    >
      <ul className="divide-y divide-n-100">
        <li>
          <Link
            href={href()}
            aria-current={chosenId === undefined ? "page" : undefined}
            className="flex flex-col gap-0.5 px-4 py-2.5 transition-colors duration-[120ms] ease-standard hover:bg-n-50 aria-[current=page]:bg-n-50"
          >
            <span className="font-medium text-n-900">Blank</span>
          </Link>
        </li>
        {offered.map((template) => (
          <li key={template.id}>
            <Link
              href={href(template)}
              aria-current={chosenId === template.id ? "page" : undefined}
              className="flex flex-col gap-0.5 px-4 py-2.5 transition-colors duration-[120ms] ease-standard hover:bg-n-50 aria-[current=page]:bg-n-50"
            >
              <span className="font-medium text-n-900">
                {template.project && (
                  <span className="mr-1.5 font-mono text-caption text-n-500">{template.project.key}</span>
                )}
                {template.name}
              </span>
              {template.purpose && (
                <span className="max-w-prose text-caption text-n-500">{template.purpose}</span>
              )}
            </Link>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

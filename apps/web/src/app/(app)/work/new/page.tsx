import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { Panel } from "@/components/ui/Panel";
import type { CustomFieldAnswer } from "@/features/custom-fields/types";
import { NewWorkItemForm } from "@/features/work-item/components/NewWorkItemForm";
import {
  prefillFrom,
  type WorkItemTemplate,
  type WorkVocabulary,
} from "@/features/work-item/templates";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * New work item (docs/08 §4).
 *
 * A page, not a dialog. It renders complete on the server with the projects and
 * people it needs, it survives a reload, and — the reason that matters — it is
 * a link, so the board's "New work item" button carries `?project=KEY` and the
 * form opens with that question already answered. The same button on My Work
 * carries nothing, because work with no project is a first-class case
 * (ADR 0004), not an omission.
 *
 * A template is chosen the same way: `?template=ID` is a link, and the form
 * opens prefilled (ADR 0047). Nothing is created by choosing one. The person
 * still sees every field, can change any of them, and submits to the same
 * `POST /work-items` as a blank form — so a required custom field, a
 * permission, a project's rules all meet them exactly as they would anyway.
 *
 * The types and priorities used to be written out here, under a comment
 * admitting they were a copy of `WorkItemModel`'s constants and predicting that
 * the fix would be "one endpoint that names them, not a fourth copy". The
 * template editor needed the same lists, so the endpoint exists now and this
 * page reads it.
 */

type ProjectOption = { id: string; key: string; name: string };
type PersonOption = { id: string; name: string | null };

export default async function NewWorkItemPage({
  searchParams,
}: {
  searchParams: Promise<{ project?: string; template?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  // Checked here as well as by the API, and for a different purpose: the API
  // decides, this decides what to render. A form that 403s on submit is a form
  // that wasted somebody's typing.
  if (!me.permissions.includes("work_item.create")) {
    return (
      <div className="space-y-5">
        <PageHeader title="New work item" />
        <EmptyState
          title="You cannot create work here"
          description="Creating work needs the work_item.create permission. Your administrator grants it with a role."
        />
      </div>
    );
  }

  const [projects, people, customFields, vocabulary, templates] = await Promise.all([
    api<ProjectOption[]>("/projects?limit=200")
      .then((r) => r.data)
      .catch(() => [] as ProjectOption[]),
    // Active members only, and 200 of them: a longer list than any select
    // should hold, and the point at which this needs the search field the
    // people directory already has rather than a bigger cap.
    api<PersonOption[]>("/people?limit=200")
      .then((r) => r.data)
      .catch(() => [] as PersonOption[]),
    // Its own endpoint, not the administration one: `/custom-fields/work_item`
    // is guarded by `custom_field.manage`, which nobody filling this form is
    // required to hold (ADR 0038).
    api<CustomFieldAnswer[]>("/work-items/fields")
      .then((r) => r.data)
      .catch(() => [] as CustomFieldAnswer[]),
    // Not caught. With no vocabulary the type and priority selects would offer
    // only "Default", which looks like a deliberate choice and is an outage.
    api<WorkVocabulary>("/work-items/vocabulary").then((r) => r.data),
    // Caught to null, not to []: an empty list would render as "this
    // organization has no templates", and a failure is not that.
    api<WorkItemTemplate[]>("/work-item-templates")
      .then((r) => r.data)
      .catch(() => null),
  ]);

  const fromProject = projects.find((project) => project.key === params.project);
  const chosen = templates?.find((template) => template.id === params.template);

  // The person's own calendar day, not the server's: "due in 2 days" opened at
  // 07:00 in Makassar is still yesterday in UTC.
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: me.user.timezone }).format(
    new Date(),
  );

  return (
    <div className="space-y-5">
      <PageHeader
        title="New work item"
        description={
          fromProject === undefined
            ? "Unassigned to a project unless you choose one."
            : `In ${fromProject.name}.`
        }
      />

      <TemplatePicker
        templates={templates}
        chosenId={chosen?.id}
        projectKey={fromProject?.key}
        // A `?template=` that names nothing — deleted since the link was made —
        // is said out loud rather than quietly ignored.
        missing={params.template !== undefined && templates !== null && chosen === undefined}
      />

      <NewWorkItemForm
        // Remounted when the template changes, so the form's state starts from
        // the new prefill rather than keeping whatever the last one set.
        key={chosen?.id ?? "blank"}
        projects={projects.map((project) => ({
          id: project.id,
          key: project.key,
          label: `${project.key} · ${project.name}`,
        }))}
        people={people.map((person) => ({
          id: person.id,
          label: person.name ?? "Unnamed",
        }))}
        defaultProjectId={fromProject?.id}
        projectKey={fromProject?.key}
        customFields={customFields}
        types={vocabulary.types}
        priorities={vocabulary.priorities}
        template={
          chosen === undefined
            ? undefined
            : { name: chosen.name, prefill: prefillFrom(chosen, customFields, today, vocabulary.types) }
        }
      />
    </div>
  );
}

/**
 * The templates, as links.
 *
 * Links and not a select with a handler: choosing a template GOES somewhere —
 * the same page with `?template=ID` — so it is an anchor, it survives a reload,
 * and it can be pasted to a colleague ("use this one"). The project the page
 * was opened from travels with it.
 */
function TemplatePicker({
  templates,
  chosenId,
  projectKey,
  missing,
}: {
  templates: WorkItemTemplate[] | null;
  chosenId?: string;
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
  if (templates.length === 0) return null;

  const href = (templateId?: string): string => {
    const query = new URLSearchParams();

    if (projectKey !== undefined) query.set("project", projectKey);
    if (templateId !== undefined) query.set("template", templateId);

    const qs = query.toString();

    return qs === "" ? "/work/new" : `/work/new?${qs}`;
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
        {templates.map((template) => (
          <li key={template.id}>
            <Link
              href={href(template.id)}
              aria-current={chosenId === template.id ? "page" : undefined}
              className="flex flex-col gap-0.5 px-4 py-2.5 transition-colors duration-[120ms] ease-standard hover:bg-n-50 aria-[current=page]:bg-n-50"
            >
              <span className="font-medium text-n-900">{template.name}</span>
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

import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import type { CustomFieldAnswer } from "@/features/custom-fields/types";
import { NewWorkItemForm } from "@/features/work-item/components/NewWorkItemForm";
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
 * The vocabularies are hard-coded here and that is a deliberate, uncomfortable
 * choice: `WorkItemModel::TYPES` and `PRIORITIES` are PHP constants with no
 * endpoint exposing them. Two lists that must agree eventually will not — the
 * lesson this codebase has learned three times — so the comment above each says
 * where its twin lives. When Phase 7's custom fields need the same thing, the
 * fix is one endpoint that names them, not a fourth copy.
 */

/** Mirrors `WorkItemModel::TYPES`. */
const TYPES = [
  "task",
  "request",
  "approval_work",
  "incident",
  "review",
  "campaign",
  "operational",
];

/** Mirrors `WorkItemModel::PRIORITIES`. */
const PRIORITIES = ["low", "medium", "high", "urgent"];

type ProjectOption = { id: string; key: string; name: string };
type PersonOption = { id: string; name: string | null };

export default async function NewWorkItemPage({
  searchParams,
}: {
  searchParams: Promise<{ project?: string }>;
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

  const [projects, people, customFields] = await Promise.all([
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
  ]);

  const fromProject = projects.find((project) => project.key === params.project);

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

      <NewWorkItemForm
        projects={projects.map((project) => ({
          id: project.id,
          label: `${project.key} · ${project.name}`,
        }))}
        people={people.map((person) => ({
          id: person.id,
          label: person.name ?? "Unnamed",
        }))}
        defaultProjectId={fromProject?.id}
        projectKey={fromProject?.key}
        customFields={customFields}
        types={TYPES}
        priorities={PRIORITIES}
      />
    </div>
  );
}

import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { NewProjectForm } from "@/features/project/NewProjectForm";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * New project (docs/08 §2).
 *
 * The departments are fetched here and nowhere else in the product: this is the
 * first screen that reads `GET /departments`, which Phase 2 shipped and nothing
 * called. Department ADMINISTRATION is still owed — creating, renaming and
 * moving one — and the reachability guard now says exactly that, per verb,
 * instead of exempting the whole path.
 *
 * A failed department fetch is not a failed page. The field is optional, so an
 * empty list degrades to "None" rather than blocking a project from existing.
 */

type Department = { id: string; name: string; code: string | null; depth: number };

export default async function NewProjectPage() {
  const me = await requireUser();

  if (!me.permissions.includes("project.create")) {
    return (
      <div className="space-y-5">
        <PageHeader title="New project" />
        <EmptyState
          title="You cannot create projects here"
          description="Creating a project needs the project.create permission. Your administrator grants it with a role."
        />
      </div>
    );
  }

  const departments = await api<Department[]>("/departments")
    .then((r) => r.data)
    .catch(() => [] as Department[]);

  return (
    <div className="space-y-5">
      <PageHeader
        title="New project"
        description="You will own it, and be its first member."
      />

      <NewProjectForm
        departments={departments.map((department) => ({
          id: department.id,
          // Indented by depth: the list is ordered by `path`, so nesting is the
          // only thing that makes a flat select of an org chart readable.
          label: `${"— ".repeat(department.depth)}${department.name}`,
        }))}
      />
    </div>
  );
}

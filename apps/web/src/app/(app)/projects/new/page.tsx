import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { NewProjectForm } from "@/features/project/NewProjectForm";
import type { WorkVocabulary } from "@/features/work-item/templates";
import { api } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
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
  // Translated (ADR 0060).
  const t = translator(asLocale(me.user.locale));

  if (!me.permissions.includes("project.create")) {
    return (
      <div className="space-y-5">
        <PageHeader title={t("projects.new")} />
        <EmptyState
          title={t("pnew.cannot.title")}
          description={t("pnew.cannot.body")}
        />
      </div>
    );
  }

  const [departments, priorities] = await Promise.all([
    api<Department[]>("/departments")
      .then((r) => r.data)
      .catch(() => [] as Department[]),
    // Served, not copied (ADR 0047). If it fails the form still offers
    // "Default", which is a real answer.
    api<WorkVocabulary>("/work-items/vocabulary")
      .then((r) => r.data.priorities)
      .catch(() => [] as string[]),
  ]);

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("projects.new")}
        description={t("pnew.description")}
      />

      <NewProjectForm
        priorities={priorities}
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

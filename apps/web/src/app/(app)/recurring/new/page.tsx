import Link from "next/link";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { NewRecurrenceForm } from "@/features/recurrence/NewRecurrenceForm";
import type { Person } from "@/features/people/types";
import { TemplatePicker } from "@/features/work-item/components/TemplatePicker";
import type { WorkItemTemplate, WorkVocabulary } from "@/features/work-item/templates";
import { api } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

type Project = { id: string; key: string; name: string };

export default async function NewRecurrencePage({
  searchParams,
}: {
  searchParams: Promise<{ template?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);
  // Translated (ADR 0060).
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  if (!me.permissions.includes("work_item.create")) {
    // Creating a standing instruction to create work needs the permission to
    // create work — no more, and not less either, which is exactly what the
    // route requires.
    return (
      <div className="space-y-5">
        <PageHeader title={t("rec.new")} />
        <EmptyState
          title={t("rec.cannot.title")}
          description={t("rec.cannot.body")}
        />
      </div>
    );
  }

  const [projects, people, vocabulary, templates] = await Promise.all([
    api<Project[]>("/projects?limit=100").then((r) => r.data).catch(() => [] as Project[]),
    api<Person[]>("/people?limit=100").then((r) => r.data).catch(() => [] as Person[]),
    // Not caught: an empty priority list would read as a deliberate "Default
    // only", and it would be an outage. The form kept its own copy of these
    // four words until ADR 0047 served them.
    api<WorkVocabulary>("/work-items/vocabulary").then((r) => r.data),
    // The same starting points the create form offers (ADR 0047): recurring
    // work is a work item that keeps being made. Caught to null — a failure is
    // not "no templates".
    api<WorkItemTemplate[]>("/work-item-templates")
      .then((r) => r.data)
      .catch(() => null),
  ]);

  const chosen = templates?.find((template) => template.id === params.template);

  return (
    <div className="space-y-5">
      <Link href="/recurring" className="text-body-sm text-n-500 hover:text-a-700">
        {t("rec.back")}
      </Link>

      <PageHeader
        title={t("rec.new")}
        description={t("rec.newDesc")}
      />

      <TemplatePicker
        basePath="/recurring/new"
        templates={templates}
        chosenId={chosen?.id}
        missing={params.template !== undefined && templates !== null && chosen === undefined}
        locale={locale}
      />

      <NewRecurrenceForm
        // Remounted when the template changes, so the form starts from the
        // new one rather than keeping what the last one filled in.
        key={chosen?.id ?? "blank"}
        template={
          chosen === undefined
            ? undefined
            : { name: chosen.name, fields: chosen.fields, projectId: chosen.project?.id }
        }
        projects={projects.map((project) => ({
          id: project.id,
          label: `${project.key} · ${project.name}`,
        }))}
        // Without `work_item.assign` a rule may only make work for its author:
        // the API refuses anyone else, so the form offers no one else.
        people={people
          .filter((person) => me.permissions.includes("work_item.assign") || person.id === me.membership.id)
          .map((person) => ({ id: person.id, label: person.name }))}
        priorities={vocabulary.priorities}
      />
    </div>
  );
}

import Link from "next/link";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { NewRecurrenceForm } from "@/features/recurrence/NewRecurrenceForm";
import type { Person } from "@/features/people/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

type Project = { id: string; key: string; name: string };

export default async function NewRecurrencePage() {
  const me = await requireUser();

  if (!me.permissions.includes("work_item.create")) {
    // Creating a standing instruction to create work needs the permission to
    // create work — no more, and not less either, which is exactly what the
    // route requires.
    return (
      <div className="space-y-5">
        <PageHeader title="New recurring work" />
        <EmptyState
          title="You cannot set up recurring work"
          description="It needs the work_item.create permission — the same one that lets you create a work item. Your administrator grants it with a role."
        />
      </div>
    );
  }

  const [projects, people] = await Promise.all([
    api<Project[]>("/projects?limit=100").then((r) => r.data).catch(() => [] as Project[]),
    api<Person[]>("/people?limit=100").then((r) => r.data).catch(() => [] as Person[]),
  ]);

  return (
    <div className="space-y-5">
      <Link href="/recurring" className="text-body-sm text-n-500 hover:text-a-700">
        ← Recurring work
      </Link>

      <PageHeader
        title="New recurring work"
        description="The same work item, created on a schedule. Every one it makes points back at this rule."
      />

      <NewRecurrenceForm
        projects={projects.map((project) => ({
          id: project.id,
          label: `${project.key} · ${project.name}`,
        }))}
        people={people.map((person) => ({ id: person.id, label: person.name }))}
      />
    </div>
  );
}

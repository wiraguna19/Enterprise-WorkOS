import Link from "next/link";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { NewTeamForm } from "@/features/organization/NewTeamForm";
import type { Person } from "@/features/people/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

type Department = { id: string; name: string; depth: number };

export default async function NewTeamPage() {
  const me = await requireUser();

  if (!me.permissions.includes("team.create")) {
    return (
      <div className="space-y-5">
        <PageHeader title="New team" />
        <EmptyState
          title="You cannot create teams here"
          description="Creating a team needs the team.create permission. Your administrator grants it with a role."
        />
      </div>
    );
  }

  // Both optional, so neither failure blocks the page. A directory this reader
  // may not see leaves the lead unset rather than refusing the team — the API
  // decides whether a membership may be named, and an empty picker is a
  // truthful "nobody to choose from here".
  const [departments, people] = await Promise.all([
    api<Department[]>("/departments").then((r) => r.data).catch(() => [] as Department[]),
    api<Person[]>("/people?limit=100").then((r) => r.data).catch(() => [] as Person[]),
  ]);

  return (
    <div className="space-y-5">
      <Link href="/teams" className="text-body-sm text-n-500 hover:text-a-700">
        ← Teams
      </Link>

      <PageHeader
        title="New team"
        description="People who work together, so work can be found by the group that owns it."
      />

      <NewTeamForm
        departments={departments.map((department) => ({
          id: department.id,
          label: `${"— ".repeat(department.depth)}${department.name}`,
        }))}
        people={people.map((person) => ({ id: person.id, label: person.name }))}
      />
    </div>
  );
}

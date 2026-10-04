import Link from "next/link";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { NewTeamForm } from "@/features/organization/NewTeamForm";
import type { Person } from "@/features/people/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

type Department = { id: string; name: string; depth: number };

export default async function NewTeamPage() {
  const me = await requireUser();
  const t = translator(asLocale(me.user.locale));

  if (!me.permissions.includes("team.create")) {
    return (
      <div className="space-y-5">
        <PageHeader title={t("teams.new")} />
        <EmptyState
          title={t("tnew.cannot.title")}
          description={t("tnew.cannot.body")}
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
        {t("tnew.back")}
      </Link>

      <PageHeader
        title={t("teams.new")}
        description={t("tnew.description")}
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

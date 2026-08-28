import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import type { Team } from "@/features/teams/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * Teams (docs/02 §4, docs/08 §2).
 *
 * A list, not a grid of cards: a team is a name, a department, and a size, and
 * three facts do not need a container each.
 */
export default async function TeamsPage() {
  await requireUser();

  const { data: teams } = await api<Team[]>("/teams");

  return (
    <div className="space-y-6">
      <PageHeader title="Teams" description={`${teams.length} active`} />

      {teams.length === 0 ? (
        <EmptyState
          title="No teams yet"
          description="Teams group people who work together, so work can be found by the group that owns it rather than person by person."
        />
      ) : (
        <ul className="divide-y divide-n-100 border-y border-n-100">
          {teams.map((team) => (
            <li key={team.id}>
              <Link
                href={`/teams/${team.id}`}
                className="flex items-center gap-3 py-2.5 transition-colors duration-[120ms] hover:bg-n-25"
              >
                <span className="w-14 shrink-0 font-mono text-caption text-n-500">{team.key}</span>

                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium text-n-900">{team.name}</span>
                  <span className="block truncate text-caption text-n-500">
                    {team.description || "—"}
                  </span>
                </span>

                <span className="shrink-0 text-caption text-n-500">
                  {team.department?.name ?? "No department"}
                </span>

                <span className="w-16 shrink-0 text-right text-caption text-n-500">
                  {team.member_count ?? 0} {team.member_count === 1 ? "person" : "people"}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

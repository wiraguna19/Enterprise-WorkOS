import { notFound } from "next/navigation";
import Link from "next/link";
import { ButtonLink } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { Panel } from "@/components/ui/Panel";
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
  const me = await requireUser();

  // The nav hides this entry without the permission; the PAGE has to refuse it
  // too. A URL is typed, pasted and bookmarked, and until now the two screens
  // whose reads have no fallback answered a 403 with "Something went wrong"
  // while the two that do fall back answered with an empty state — which is
  // worse, because "no departments yet" is a confident lie about somebody
  // else's organization. 404 rather than 403, like every other refusal in this
  // product: whether the thing exists is not this page's to disclose.
  if (!me.permissions.includes("team.view")) notFound();


  const { data: teams } = await api<Team[]>("/teams");

  return (
    <div className="space-y-6">
      <PageHeader
        title="Teams"
        description={`${teams.length} active`}
        action={
          me.permissions.includes("team.create") ? (
            <ButtonLink href="/teams/new" variant="primary">
              New team
            </ButtonLink>
          ) : undefined
        }
      />

      <PageBody>
        {teams.length === 0 ? (
          <EmptyState
            title="No teams yet"
            description="Teams group people who work together, so work can be found by the group that owns it rather than person by person."
            action={
              // The empty state is the worse of the two dead buttons to leave
              // behind: it is what a brand-new organization sees first.
              me.permissions.includes("team.create") ? (
                <ButtonLink href="/teams/new" variant="primary">
                  Create the first team
                </ButtonLink>
              ) : undefined
            }
          />
        ) : (
          <Panel
            id="teams"
            title="Teams"
            description={`${teams.length} ${teams.length === 1 ? "team" : "teams"}`}
            bleed
          >
            <DataTable caption="Teams in this organization">
              <THead>
                <Tr>
                  <Th width="w-20">Key</Th>
                  <Th>Name</Th>
                  <Th>Department</Th>
                  <Th width="w-24" align="right">
                    People
                  </Th>
                </Tr>
              </THead>
              <TBody>
                {teams.map((team) => (
                  <Tr key={team.id}>
                    <Td muted>
                      <span className="font-mono text-micro">{team.key}</span>
                    </Td>
                    <Td>
                      <Link href={`/teams/${team.id}`} className="block min-w-0 hover:text-a-700">
                        <span className="block truncate font-medium text-n-900">{team.name}</span>
                        <span className="block truncate text-caption text-n-500">
                          {team.description || "—"}
                        </span>
                      </Link>
                    </Td>
                    <Td muted>{team.department?.name ?? "No department"}</Td>
                    <Td align="right" muted>
                      <span className="tabular-nums">{team.member_count ?? 0}</span>
                    </Td>
                  </Tr>
                ))}
              </TBody>
            </DataTable>
          </Panel>
        )}
      </PageBody>
    </div>
  );
}

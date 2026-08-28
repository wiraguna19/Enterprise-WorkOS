import Link from "next/link";
import { notFound } from "next/navigation";
import { PageHeader } from "@/components/ui/PageHeader";
import { WorkItemRow } from "@/features/work-item/components/WorkItemRow";
import type { WorkItem } from "@/features/work-item/types";
import { TeamMembers } from "@/features/teams/TeamMembers";
import type { Team } from "@/features/teams/types";
import type { Person, Workload } from "@/features/people/types";
import { WorkloadBar } from "@/components/ui/WorkloadBar";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * A team's workspace (docs/08 §2).
 *
 * The work shown is work assigned to people currently on the team — teams do
 * not own work items, people do (docs/03 §2). Which means this list changes
 * when someone joins or leaves, and that is correct: it answers "what is this
 * team carrying right now", not "what has this team ever touched".
 */
export default async function TeamPage({ params }: { params: Promise<{ id: string }> }) {
  const [me, { id }] = await Promise.all([requireUser(), params]);

  let team: Team;

  try {
    ({ data: team } = await api<Team>(`/teams/${id}`));
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  const canManage = team.permissions.manage_members ?? false;

  const [{ data: work }, directory] = await Promise.all([
    api<WorkItem[]>(
      `/work-items?filter[team_id]=${id}` +
        "&filter[state_category]=todo,in_progress,in_review,blocked" +
        "&sort=due_at&limit=25",
    ).catch(() => ({ data: [] as WorkItem[] })),

    // Only fetched for the people who can act on it: everyone else gets a list
    // they cannot use, and it is a hundred rows over the wire either way.
    canManage
      ? api<Person[]>("/people?limit=100")
          .then((r) => r.data)
          .catch(() => [])
      : Promise.resolve([]),
  ]);

  // Rows, never one averaged number: an over-committed person beside an idle
  // one averages to "fine", which is the one reading of this that helps nobody
  // (docs/02 §11). Members whose workload this viewer may not see are omitted
  // and counted by the API rather than shown as zero.
  const { data: workload, meta: workloadMeta } = await api<Array<Workload & { name: string }>>(
    `/teams/${id}/workload`,
  ).catch(() => ({ data: [] as Array<Workload & { name: string }>, meta: undefined }));

  const memberIds = new Set((team.members ?? []).map((member) => member.membership_id));

  const candidates = directory
    .filter((person) => !memberIds.has(person.id))
    .map((person) => ({ id: person.id, name: person.name }));

  return (
    <div className="mx-auto max-w-4xl space-y-8">
      <div className="space-y-4">
        <Link href="/teams" className="text-body-sm text-n-500 hover:text-a-700">
          ← Teams
        </Link>

        <PageHeader
          title={team.name}
          description={
            team.description ||
            `${team.key}${team.department?.name ? ` · ${team.department.name}` : ""}`
          }
        />
      </div>

      <section aria-labelledby="members-heading" className="space-y-2">
        <SectionLabel id="members-heading">
          Members
          <span className="ml-1 font-normal normal-case tracking-normal text-n-400">
            ({team.members?.length ?? 0})
          </span>
        </SectionLabel>

        <TeamMembers
          teamId={team.id}
          members={team.members ?? []}
          candidates={candidates}
          canManage={canManage}
        />
      </section>

      {workload.length > 0 && (
        <section aria-labelledby="load-heading" className="space-y-2">
          <SectionLabel id="load-heading">This week</SectionLabel>

          <ul className="divide-y divide-n-100 border-y border-n-100">
            {workload.map((row) => (
              <li key={row.membership_id} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2">
                <Link
                  href={`/people/${row.membership_id}`}
                  className="w-40 shrink-0 truncate text-body-sm text-n-900 hover:text-a-700"
                >
                  {row.name}
                </Link>

                <WorkloadBar
                  committedHours={row.committed_hours}
                  capacityHours={row.capacity_hours}
                  itemCount={row.item_count}
                  unestimatedCount={row.unestimated_count}
                />
              </li>
            ))}
          </ul>

          {typeof workloadMeta?.withheld_count === "number" && workloadMeta.withheld_count > 0 && (
            // Said out loud rather than left as a shorter list: a team view
            // that quietly omits people misrepresents the team.
            <p className="text-caption text-n-500">
              {workloadMeta.withheld_count} member
              {workloadMeta.withheld_count === 1 ? "'s" : "s'"} workload is not yours to see.
            </p>
          )}
        </section>
      )}

      <section aria-labelledby="work-heading" className="space-y-2">
        <SectionLabel id="work-heading">Open work</SectionLabel>

        {work.length === 0 ? (
          <p className="text-body-sm text-n-400">Nothing open.</p>
        ) : (
          <div className="border-t border-n-100">
            {work.map((item) => (
              <WorkItemRow key={item.id} item={item} timeZone={me.user.timezone} />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}

function SectionLabel({ id, children }: { id: string; children: React.ReactNode }) {
  return (
    <h2
      id={id}
      className="text-micro font-semibold uppercase tracking-[0.04em] text-n-500"
    >
      {children}
    </h2>
  );
}

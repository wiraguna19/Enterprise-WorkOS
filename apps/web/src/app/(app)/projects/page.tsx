import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { Button } from "@/components/ui/Button";
import type { Project } from "@/features/work-item/types";
import { formatDateTime } from "@/lib/format";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { clsx } from "@/lib/clsx";

/**
 * The project directory (docs/08 §2).
 *
 * Sorted so the projects that need attention are legible at a glance, and the
 * "health" signal is the overdue COUNT — a real number that links to the work —
 * rather than a red/amber/green dot nobody can trace back to anything.
 */
export default async function ProjectsPage() {
  const me = await requireUser();
  const { data: projects } = await api<Project[]>("/projects");

  return (
    <div className="space-y-5">
      <PageHeader
        title="Projects"
        description={`${projects.length} active in ${me.organization.name}`}
        action={
          me.permissions.includes("project.create") ? (
            <Button variant="primary">New project</Button>
          ) : undefined
        }
      />

      {projects.length === 0 ? (
        <EmptyState
          title="No projects yet"
          description="A project groups work, milestones, and the people doing it. Work does not have to live in one — requests and incidents exist on their own."
          action={
            me.permissions.includes("project.create") ? (
              <Button variant="primary">Create the first project</Button>
            ) : undefined
          }
        />
      ) : (
        <ul className="divide-y divide-n-100 border-y border-n-100">
          {projects.map((project) => (
            <li key={project.id}>
              <Link
                href={`/projects/${project.key}/overview`}
                className="flex items-center gap-4 py-3 transition-colors duration-[120ms] hover:bg-n-25"
              >
                <span className="w-12 shrink-0 font-mono text-caption text-n-500">
                  {project.key}
                </span>

                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium text-n-900">{project.name}</span>
                  <span className="mt-0.5 flex items-center gap-1.5 text-caption text-n-500">
                    <span className="capitalize">{project.status.replace("_", " ")}</span>
                    {project.visibility === "private" && (
                      <>
                        <span aria-hidden>·</span>
                        <span>private</span>
                      </>
                    )}
                    {project.member_count !== undefined && (
                      <>
                        <span aria-hidden>·</span>
                        <span>{project.member_count} members</span>
                      </>
                    )}
                  </span>
                </span>

                {/* Progress is derived and cached (docs/02 §5, docs/12 §8), so
                    the row shows WHEN it was computed rather than implying it
                    is live — and shows nothing at all until it has been.

                    This bar rendered a confident 0% for every project from
                    Phase 2 until the rollup was written: the column existed,
                    the job did not, and a figure that looks computed and is not
                    is worse than a blank, because nobody goes looking for the
                    bug. */}
                <span className="hidden w-40 shrink-0 items-center gap-2 sm:flex">
                  {project.progress_as_of === null ? (
                    <span className="flex-1 text-right text-caption text-n-500">
                      not computed yet
                    </span>
                  ) : (
                    <>
                      <span
                        className="h-1.5 flex-1 overflow-hidden rounded-full bg-n-100"
                        title={`As of ${formatDateTime(project.progress_as_of, me.user.timezone)}`}
                      >
                        <span
                          className="block h-full rounded-full bg-a-500"
                          style={{ width: `${project.progress}%` }}
                        />
                      </span>
                      <span className="w-8 text-right text-caption tabular-nums text-n-500">
                        {Math.round(project.progress)}%
                      </span>
                    </>
                  )}
                </span>

                <span className="w-28 shrink-0 text-right text-caption tabular-nums">
                  <span className="text-n-700">{project.open_work_count ?? 0} open</span>
                  {(project.overdue_work_count ?? 0) > 0 && (
                    <span className={clsx("ml-1.5 font-semibold text-s-danger")}>
                      {project.overdue_work_count} late
                    </span>
                  )}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

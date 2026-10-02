import { notFound } from "next/navigation";
import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { PinToggle } from "@/features/project/PinToggle";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { Panel } from "@/components/ui/Panel";
import { ButtonLink } from "@/components/ui/Button";
import type { Project } from "@/features/work-item/types";
import { formatDateTime } from "@/lib/format";
import { api } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import { translator } from "@/i18n/translate";
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

  // The nav hides this entry without the permission; the PAGE has to refuse it
  // too. A URL is typed, pasted and bookmarked, and until now the two screens
  // whose reads have no fallback answered a 403 with "Something went wrong"
  // while the two that do fall back answered with an empty state — which is
  // worse, because "no departments yet" is a confident lie about somebody
  // else's organization. 404 rather than 403, like every other refusal in this
  // product: whether the thing exists is not this page's to disclose.
  if (!me.permissions.includes("project.view")) notFound();

  // Translated (ADR 0060).
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  // The five statuses a project can be in, in the reader's language; one the
  // API adds later is shown as it arrives.
  const projectStatus = (status: string): string =>
    ["planning", "active", "on_hold", "completed", "cancelled"].includes(status)
      ? t(`project.status.${status}` as MessageKey)
      : status.replace("_", " ");

  const [{ data: projects }, pinned] = await Promise.all([
    api<Project[]>("/projects"),
    // Its own small read rather than another subquery on the directory: the
    // layout already asks for this list on every request, so it is cached and
    // cheap, and the alternative adds a per-row join for a star (ADR 0044).
    api<Array<{ id: string }>>("/me/projects")
      .then((r) => new Set(r.data.map((project) => project.id)))
      .catch(() => new Set<string>()),
  ]);

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("nav.projects")}
        description={t("projects.summary", { count: projects.length, org: me.organization.name })}
        action={
          me.permissions.includes("project.create") ? (
            <ButtonLink variant="primary" href="/projects/new">
              {t("projects.new")}
            </ButtonLink>
          ) : undefined
        }
      />

      <PageBody>
        {projects.length === 0 ? (
          <EmptyState
            title={t("projects.empty.title")}
            description={t("projects.empty.body")}
            action={
              me.permissions.includes("project.create") ? (
                <ButtonLink variant="primary" href="/projects/new">
                  {t("projects.createFirst")}
                </ButtonLink>
              ) : undefined
            }
          />
        ) : (
          <Panel
            id="projects"
            title={t("nav.projects")}
            description={t.plural("projects.count", projects.length)}
            bleed
          >
            <DataTable caption={t("projects.caption")}>
              <THead>
                <Tr>
                  {/* No heading: the column is a row of toggles, and "Pin"
                      over a star reads as an instruction rather than a label.
                      Each button carries its own accessible name. */}
                  <Th width="w-8">
                    <span className="sr-only">{t("projects.col.pinned")}</span>
                  </Th>
                  <Th width="w-16">{t("projects.col.key")}</Th>
                  <Th>{t("projects.col.name")}</Th>
                  <Th width="w-48">{t("projects.col.progress")}</Th>
                  <Th width="w-32" align="right">
                    {t("projects.col.work")}
                  </Th>
                </Tr>
              </THead>
              <TBody>
                {projects.map((project) => (
                  <Tr key={project.id}>
                    <Td>
                      <PinToggle
                        projectKey={project.key}
                        name={project.name}
                        pinned={pinned.has(project.id)}
                      />
                    </Td>

                    <Td muted>
                      <span className="font-mono text-micro">{project.key}</span>
                    </Td>

                    <Td>
                      <Link
                        href={`/projects/${project.key}/overview`}
                        className="block min-w-0 hover:text-a-700"
                      >
                        <span className="block truncate font-medium text-n-900">
                          {project.name}
                        </span>
                        <span className="mt-0.5 flex items-center gap-1.5 text-caption text-n-500">
                          <span className="capitalize">{projectStatus(project.status)}</span>
                          {project.visibility === "private" && (
                            <>
                              <span aria-hidden>·</span>
                              <span>{t("projects.private")}</span>
                            </>
                          )}
                          {project.member_count !== undefined && (
                            <>
                              <span aria-hidden>·</span>
                              <span>{t("projects.members", { count: project.member_count })}</span>
                            </>
                          )}
                        </span>
                      </Link>
                    </Td>

                    {/* Progress is derived and cached (docs/02 §5, docs/12 §8),
                        so the row shows WHEN it was computed rather than
                        implying it is live — and shows nothing at all until it
                        has been.

                        This bar rendered a confident 0% for every project from
                        Phase 2 until the rollup was written: the column
                        existed, the job did not, and a figure that looks
                        computed and is not is worse than a blank, because
                        nobody goes looking for the bug. */}
                    <Td muted>
                      {project.progress_as_of === null ? (
                        t("projects.notComputed")
                      ) : project.progress === null ? (
                        /* Counted, and there was nothing to count. The bar
                           used to render 0% here, which reads as "none of it
                           is done" about a project nobody has put work in yet
                           — the same confident-zero the comment above already
                           describes, surviving the fix for it (ADR 0042). */
                        <span className="text-n-500">{t("projects.noWork")}</span>
                      ) : (
                        <span className="flex items-center gap-2">
                          <span
                            className="h-1.5 flex-1 overflow-hidden rounded-full bg-n-100"
                            title={t("projects.asOf", {
                              date: formatDateTime(project.progress_as_of, me.user.timezone, locale),
                            })}
                          >
                            <span
                              className="block h-full rounded-full bg-a-500"
                              style={{ width: `${project.progress}%` }}
                            />
                          </span>
                          <span className="w-8 text-right tabular-nums">
                            {Math.round(project.progress)}%
                          </span>
                        </span>
                      )}
                    </Td>

                    <Td align="right">
                      <span className="tabular-nums text-n-700">
                        {t("count.open", { count: project.open_work_count ?? 0 })}
                      </span>
                      {(project.overdue_work_count ?? 0) > 0 && (
                        <span className={clsx("ml-1.5 font-semibold tabular-nums text-s-danger")}>
                          {t("projects.late", { count: project.overdue_work_count ?? 0 })}
                        </span>
                      )}
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

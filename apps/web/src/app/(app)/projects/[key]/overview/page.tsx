import Link from "next/link";
import { notFound } from "next/navigation";
import { ButtonLink } from "@/components/ui/Button";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { HealthSignals, StatusDot } from "@/features/insights/HealthSignals";
import { Milestones } from "@/features/project/Milestones";
import type { Milestone } from "@/features/project/actions";
import { ProjectTabs } from "@/features/project/ProjectTabs";
import type { Health } from "@/features/insights/types";
import type { Project } from "@/features/work-item/types";
import { api, ApiRequestError } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

/**
 * Project overview — health, and why (docs/08 §2, ADR 0008).
 *
 * Five signals with their verdicts, the rule that produced each, and a link to
 * the records behind it. No composite score, no gauge, no 0–100: "explainable,
 * not a black box" rules those out, and every one of them answers "how bad"
 * while refusing to answer "why".
 *
 * The overall verdict is the worst signal, and it is printed with the signals
 * directly underneath rather than alone at the top of a page you have to
 * scroll — an amber badge with its reason two screens away is an amber badge
 * nobody believes.
 */
export default async function ProjectOverviewPage({
  params,
}: {
  params: Promise<{ key: string }>;
}) {
  const [me, { key }] = await Promise.all([requireUser(), params]);

  let project: Project;
  let health: Health;
  let milestones: Milestone[];

  try {
    // In parallel: the header and the signals are two reads of the same page,
    // and doing them in sequence would double the time to first paint.
    [{ data: project }, { data: health }, { data: milestones }] = await Promise.all([
      api<Project>(`/projects/${key}`),
      api<Health>(`/insights/projects/${key}/health`),
      api<Milestone[]>(`/projects/${key}/milestones`),
    ]);
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  // Translated (ADR 0060).
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  return (
    <div className="space-y-5">
      <PageHeader
        title={project.name}
        description={
          health.progress_percent === null
            ? t("count.open", { count: health.open_count })
            : t("overview.progress", {
                percent: Math.round(health.progress_percent),
                open: t("count.open", { count: health.open_count }),
              })
        }
        action={
          project.permissions.create_work ? (
            <ButtonLink variant="primary" href={`/work/new?project=${project.key}`}>
              {t("myWork.new")}
            </ButtonLink>
          ) : undefined
        }
      />

      <ProjectTabs
        projectKey={project.key}
        active="overview"
        canManage={project.permissions.update ?? false}
        locale={locale}
      />

      <PageBody>
        {/* The verdict, the five signals it came from, and the two sentences
            that explain how it was computed — one panel, because they are one
            thought. They used to be four loose blocks in a column with no
            edges: a status dot, a paragraph, a bare list and a footnote, each
            floating at the same level as the others (ADR 0024). */}
        <Panel
          id="health"
          title={t("overview.health.title")}
          description={t("overview.health.description")}
          actions={<StatusDot status={health.status} locale={locale} />}
          footer={
            <div className="space-y-2">
              <p className="max-w-prose text-caption text-n-500">
                {t("overview.health.method")}
              </p>

              {/* A report about a subject is reached from the subject. There is
                  no index of reports, on purpose: "which project?" is a
                  question the page you came from has already answered. */}
              <p className="text-caption text-n-500">
                <Link
                  href={`/reports/project?project=${project.key}`}
                  className="text-a-500 underline underline-offset-2"
                >
                  {t("overview.report.link")}
                </Link>{" "}
                {t("overview.report.rest")}
              </p>
            </div>
          }
          bleed
        >
          <HealthSignals health={health} projectKey={project.key} locale={locale} />
        </Panel>

        <Milestones
          projectKey={project.key}
          milestones={milestones}
          canManage={project.permissions.manage_milestones ?? false}
          // The reader's own day: "past due" at 07:00 in Makassar is not
          // decided by the server's UTC midnight.
          today={new Intl.DateTimeFormat("en-CA", { timeZone: me.user.timezone }).format(new Date())}
        />
      </PageBody>
    </div>
  );
}

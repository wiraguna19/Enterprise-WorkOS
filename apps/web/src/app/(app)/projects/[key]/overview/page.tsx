import { notFound } from "next/navigation";
import { PageHeader } from "@/components/ui/PageHeader";
import { HealthSignals, StatusDot } from "@/features/insights/HealthSignals";
import { ProjectTabs } from "@/features/project/ProjectTabs";
import type { Health } from "@/features/insights/types";
import type { Project } from "@/features/work-item/types";
import { api, ApiRequestError } from "@/lib/api";
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
  const [, { key }] = await Promise.all([requireUser(), params]);

  let project: Project;
  let health: Health;

  try {
    // In parallel: the header and the signals are two reads of the same page,
    // and doing them in sequence would double the time to first paint.
    [{ data: project }, { data: health }] = await Promise.all([
      api<Project>(`/projects/${key}`),
      api<Health>(`/insights/projects/${key}/health`),
    ]);
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title={project.name}
        description={
          health.progress_percent === null
            ? `${health.open_count} open`
            : `${Math.round(health.progress_percent)}% complete · ${health.open_count} open`
        }
      />

      <ProjectTabs projectKey={project.key} active="overview" />

      <div className="flex items-baseline gap-3">
        <StatusDot status={health.status} />
        <p className="text-caption text-n-500">
          The overall verdict is the worst of the five signals below, never an average — a
          project on fire in one dimension and quiet in four is not healthy.
        </p>
      </div>

      <HealthSignals health={health} projectKey={project.key} />

      <p className="max-w-[72ch] text-caption text-n-500">
        Progress counts completed items against everything that is not cancelled. It is
        computed from the work itself on every load rather than read from a stored figure,
        so it cannot drift from the items it describes.
      </p>
    </div>
  );
}

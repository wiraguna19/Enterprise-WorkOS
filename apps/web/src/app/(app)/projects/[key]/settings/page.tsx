import { notFound } from "next/navigation";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { EditProjectForm } from "@/features/project/EditProjectForm";
import { ProjectTabs } from "@/features/project/ProjectTabs";
import type { Project } from "@/features/work-item/types";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * Correcting a project (ADR 0040).
 *
 * This screen did not exist because the endpoint behind it did not either: a
 * project could be created and never corrected, so a typo in its name was
 * permanent and its dates could not move. Four permissions — update, archive,
 * delete, manage_members — were seeded in Phase 1 and answered by
 * `ProjectPolicy`, with no route behind any of them. **A permission consulted
 * by a policy looks consulted**, which is why the guard that watches for
 * permissions with nothing behind them did not fail.
 *
 * Fetched here, on the request that renders the form, so `lock_version` is as
 * fresh as it can be: a version read a minute ago is a conflict reported for
 * no reason.
 */
export default async function ProjectSettingsPage({
  params,
}: {
  params: Promise<{ key: string }>;
}) {
  const [, { key }] = await Promise.all([requireUser(), params]);

  let project: Project;

  try {
    ({ data: project } = await api<Project>(`/projects/${key}`));
  } catch (error) {
    // 404 covers both "does not exist" and "not visible to you", deliberately
    // indistinguishable (docs/05 §3).
    if (error instanceof ApiRequestError && error.status === 404) notFound();
    throw error;
  }

  // The server's own decision, echoed. Somebody who may see this project but
  // not change it gets a 404 rather than a form that 403s on submit — whether
  // the settings of a project exist is not this page's to disclose.
  if (!project.permissions.update) notFound();

  return (
    <div className="space-y-5">
      <PageHeader
        title={project.name}
        description={project.archived ? "Archived." : `${project.key} · ${project.status.replace(/_/g, " ")}`}
      />

      <ProjectTabs projectKey={project.key} active="settings" canManage />

      <PageBody>
        <EditProjectForm project={project} />
      </PageBody>
    </div>
  );
}

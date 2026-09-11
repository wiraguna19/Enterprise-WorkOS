import Link from "next/link";
import { notFound } from "next/navigation";
import { PageHeader } from "@/components/ui/PageHeader";
import { GraphEditor } from "@/features/workflow/GraphEditor";
import type { Vocabulary, Workflow } from "@/features/workflow/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * Editing one workflow (ADR 0015).
 *
 * Edits apply to work already in flight, immediately, because the graph is
 * edited in place — `version` and `superseded_by_id` stay unwritten until
 * somebody needs the migration that would give them meaning. That is also why
 * a state's category and key cannot change here: a rename is cosmetic, and a
 * recategorisation is retroactive over every report already published.
 */
export default async function EditWorkflowPage({ params }: { params: Promise<{ id: string }> }) {
  const [me, { id }] = await Promise.all([requireUser(), params]);

  if (!me.permissions.includes("workflow.manage")) notFound();

  const [workflows, vocabulary] = await Promise.all([
    api<Workflow[]>("/workflows", { tags: ["workflows"] }).then((r) => r.data),
    api<Vocabulary>("/workflow-vocabulary").then((r) => r.data),
  ]);

  const workflow = workflows.find((candidate) => candidate.id === id);

  if (!workflow) notFound();

  return (
    <div className="space-y-5">
      <PageHeader
        title={workflow.name}
        description={`${workflow.applies_to_type} · changes apply to work already in flight`}
      />

      <p className="text-body-sm text-n-500">
        <Link href="/settings/workflows" className="text-a-700 underline">
          All workflows
        </Link>
      </p>

      <GraphEditor workflow={workflow} vocabulary={vocabulary} />
    </div>
  );
}

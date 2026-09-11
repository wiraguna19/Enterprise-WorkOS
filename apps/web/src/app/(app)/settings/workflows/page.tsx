import Link from "next/link";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { StatusChip } from "@/components/ui/StatusChip";
import type { Workflow, WorkflowState, WorkflowTransition } from "@/features/workflow/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * The workflow catalogue (docs/02 §7).
 *
 * `GET /workflows` shipped in Phase 4 and nothing read it until this screen, so
 * the graph that decides every button in the product could only be seen by
 * querying the database. Worse, it was sending its NODES and none of its EDGES
 * — a defect nothing could report, because nobody had ever looked.
 *
 * Editing is offered to `workflow.manage` and happens in place (ADR 0015):
 * renaming a status is free, and the edits that would strand work or rewrite
 * what a finished quarter counted are refused by the API with a named reason
 * the editor prints verbatim.
 *
 * The graph is drawn per state rather than as a canvas: what an administrator
 * comes here to check is "from here, where can work go, and who is stopped" —
 * a question about one state's out-edges, which a list answers better than a
 * picture. A canvas is worth building when a person can move the nodes.
 */
export default async function WorkflowsPage() {
  const me = await requireUser();

  const { data: workflows } = await api<Workflow[]>("/workflows", {
    tags: ["workflows"],
  });

  const mayManage = me.permissions.includes("workflow.manage");

  return (
    <div className="space-y-6">
      <PageHeader title="Workflows" description={`${workflows.length} active`} />

      {workflows.length === 0 ? (
        <EmptyState
          title="No active workflow"
          description="Every work item follows a workflow, so an empty list here means work has nowhere to move. This is a configuration problem rather than an empty screen."
        />
      ) : (
        workflows.map((workflow) => (
          <WorkflowGraph key={workflow.id} workflow={workflow} mayManage={mayManage} />
        ))
      )}
    </div>
  );
}

function WorkflowGraph({ workflow, mayManage }: { workflow: Workflow; mayManage: boolean }) {
  const headingId = `workflow-${workflow.id}`;

  const byId = new Map(workflow.states.map((state) => [state.id, state]));

  // Edges that leave any state at all, kept apart from the ones that leave a
  // particular state. NULL means "from anywhere", and expanding it into one
  // edge per state would claim somebody enumerated them (docs/02 §7).
  const fromAnywhere = workflow.transitions.filter((t) => t.from_state_id === null);

  // A state nothing moves work INTO is the same defect class this screen was
  // built to pay off, one level down: configuration that exists and cannot be
  // reached. Cheap to check here, and invisible everywhere else.
  // A wildcard edge does not make every state reachable — it makes its own
  // TARGET reachable from everywhere, and that target is already in this list.
  const reachable = new Set(workflow.transitions.map((t) => t.to_state_id));

  return (
    <section aria-labelledby={headingId} className="space-y-3">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-n-100 pb-2">
        <h2 id={headingId} className="text-h2 font-semibold text-n-900">
          {workflow.name}
        </h2>
        <span className="text-caption text-n-500">
          {workflow.applies_to_type} · version {workflow.version}
          {workflow.is_default && " · default"}
        </span>

        {mayManage && (
          <Link
            href={`/settings/workflows/${workflow.id}/edit`}
            className="ml-auto text-body-sm text-a-700 underline"
          >
            Edit
          </Link>
        )}
      </div>

      <ol className="divide-y divide-n-100 border-b border-n-100">
        {workflow.states.map((state) => (
          <li key={state.id} className="flex flex-col gap-1.5 py-3 sm:flex-row sm:gap-6">
            <div className="sm:w-56 sm:shrink-0">
              <StatusChip category={state.category} label={state.label} className="font-medium" />
              <p className="mt-0.5 font-mono text-micro text-n-500">{state.key}</p>
              <Markers state={state} isReachable={reachable.has(state.id)} />
            </div>

            <Moves
              transitions={workflow.transitions.filter((t) => t.from_state_id === state.id)}
              byId={byId}
              emptyLabel={
                state.is_terminal
                  ? "Nothing follows this — work ends here."
                  : "No move leaves this state."
              }
            />
          </li>
        ))}
      </ol>

      {fromAnywhere.length > 0 && (
        <div className="flex flex-col gap-1.5 sm:flex-row sm:gap-6">
          <p className="text-body-sm font-medium text-n-700 sm:w-56 sm:shrink-0">From any state</p>
          <Moves transitions={fromAnywhere} byId={byId} emptyLabel="" />
        </div>
      )}
    </section>
  );
}

function Markers({ state, isReachable }: { state: WorkflowState; isReachable: boolean }) {
  const notes = [
    state.is_initial && "starts here",
    state.is_terminal && "ends here",
    state.requires_approval && "needs approval",
    // Deliberately worded as an observation, not a warning: a state reached
    // only by an admin override or by a workflow migration is legitimate, and
    // calling it an error would train everyone to ignore the line.
    !state.is_initial && !isReachable && "nothing moves work here",
  ].filter((note): note is string => typeof note === "string");

  if (notes.length === 0) return null;

  return <p className="mt-0.5 text-micro text-n-500">{notes.join(" · ")}</p>;
}

function Moves({
  transitions,
  byId,
  emptyLabel,
}: {
  transitions: WorkflowTransition[];
  byId: Map<string, WorkflowState>;
  emptyLabel: string;
}) {
  if (transitions.length === 0) {
    return emptyLabel ? <p className="text-caption text-n-500">{emptyLabel}</p> : null;
  }

  return (
    <ul className="min-w-0 flex-1 space-y-1">
      {transitions.map((transition) => {
        const target = byId.get(transition.to_state_id);

        return (
          <li key={transition.id} className="flex flex-wrap items-baseline gap-x-2 text-body-sm">
            <span aria-hidden className="text-n-300">
              →
            </span>
            <span className="text-n-900">{transition.label}</span>
            <span className="text-n-500">
              {/* A target the states list does not contain would mean an edge
                  into another workflow's state — impossible by construction,
                  and worth printing as itself rather than as a blank. */}
              to {target ? target.label : transition.to_state_id}
            </span>

            {transition.requires_comment && (
              <span className="text-caption text-n-500">· asks for a reason</span>
            )}

            {/* Whether a guard exists, never what it says. Who may make this
                move depends on the item and the person, and this endpoint has
                neither — the status picker on a work item is what answers it. */}
            {transition.is_guarded && (
              <span className="text-caption text-n-500">· not open to everyone</span>
            )}
          </li>
        );
      })}
    </ul>
  );
}

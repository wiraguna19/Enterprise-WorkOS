import { Button } from "@/components/ui/Button";
import type { Transition, WorkItem } from "../types";
import { StatusPicker } from "./StatusPicker";

/**
 * One primary action per screen, and it is the next legal step (docs/08 §4).
 *
 * Phase 3 shipped this as a switch on state_category — Accept → Start → Submit
 * → Approve — which read fine and was wrong: it hardcoded one workflow into the
 * client, so a workflow with different states (the request workflow already has
 * some) would show the wrong button, and a renamed state would show a stale
 * label. The brief is explicit that status must not be hardcoded, and a
 * hardcoded picker is the same violation wearing a nicer coat.
 *
 * So the button now comes from the graph. The FIRST available transition, in
 * the workflow's own ordering, is the primary action; the rest live in the
 * picker beside it. That makes "what should I do next" an answer the workflow
 * gives rather than one the frontend guesses, and it means an administrator who
 * reorders the graph reorders this button too.
 *
 * Acceptance is the one thing still decided here, and legitimately: it is a
 * property of the assignment, not of the workflow state.
 */
export function PrimaryAction({
  item,
  transitions,
}: {
  item: WorkItem;
  transitions: Transition[];
}) {
  const unaccepted = item.assignees?.some((a) => a.role === "assignee" && !a.accepted);

  // The forward moves only. A transition reachable from ANY state is an escape
  // hatch — the first version of this took the first available transition and
  // duly recommended "Mark blocked" to an assignee whose work was in review and
  // who therefore could not approve it. Technically the first available move;
  // terrible advice.
  const forward = transitions.filter((t) => !t.is_escape_hatch);

  const primary = forward.find((t) => t.available) ?? null;

  // Nothing to suggest: say why the obvious move is unavailable rather than
  // offering an escape hatch as though it were progress.
  const blocked = primary === null ? forward.find((t) => t.blocked_reason) ?? null : null;

  if (!unaccepted && primary === null && blocked === null) return null;

  return (
    <div className="sticky bottom-0 -mx-4 flex flex-wrap items-center justify-between gap-3 border-t border-n-200 bg-n-0/95 px-4 py-3 backdrop-blur md:-mx-8 md:px-8">
      <p className="text-caption text-n-500">
        {unaccepted
          ? "This work is assigned to you but not yet acknowledged."
          : primary
            ? hintFor(primary)
            : (blocked?.blocked_reason ?? "")}
      </p>

      <div className="flex items-center gap-2">
        {/* The full graph stays one click away, for the moves that are not the
            happy path — blocking, cancelling, reopening. */}
        {transitions.length > 1 && (
          <StatusPicker
            reference={item.reference}
            current={item.state ? { label: item.state.label, category: item.state.category } : null}
            transitions={transitions}
          />
        )}

        {unaccepted ? (
          <Button variant="primary" size="lg">
            Accept
          </Button>
        ) : (
          <Button variant="primary" size="lg" disabled={primary === null}>
            {(primary ?? blocked)?.label ?? "No action available"}
          </Button>
        )}
      </div>
    </div>
  );
}

/**
 * What the move will actually do.
 *
 * The category is safe to switch on where the label is not: categories are a
 * closed set the platform defines, labels are text an administrator owns.
 */
function hintFor(transition: Transition): string {
  switch (transition.to_state.category) {
    case "in_progress":
      return "Moves this to " + transition.to_state.label + " and starts the clock.";
    case "in_review":
      return "Sends this to the reviewer and opens an approval.";
    case "done":
      return "Closes this work item and stamps it complete.";
    case "cancelled":
      return "Closes this without completing it. The history is kept.";
    case "blocked":
      return "Records that this cannot proceed, and why.";
    default:
      return "Moves this to " + transition.to_state.label + ".";
  }
}

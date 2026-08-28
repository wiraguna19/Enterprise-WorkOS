import Link from "next/link";
import { clsx } from "@/lib/clsx";
import { AvatarStack } from "@/components/ui/Avatar";
import { StatusChip } from "@/components/ui/StatusChip";
import { PriorityIcon } from "./PriorityIcon";
import { DueDate } from "./DueDate";
import type { WorkItem } from "../types";

/**
 * The most-used component in the product (docs/09 §5).
 *
 * Two lines, ONE strong element (the title), everything else recessive. Quick
 * actions appear on hover rather than occupying space at rest — a row that
 * shows six buttons for every item is a row nobody can scan.
 *
 * Not a card. Cards imply each item is a separate object worth its own
 * container; a work list is something you scan down, and density is the feature.
 */
export function WorkItemRow({
  item,
  timeZone,
  showProject = true,
  selected = false,
}: {
  item: WorkItem;
  timeZone: string;
  showProject?: boolean;
  selected?: boolean;
}) {
  const unaccepted = item.assignees?.some((a) => a.role === "assignee" && !a.accepted);

  return (
    <Link
      href={`/work/${item.reference}`}
      className={clsx(
        "group flex items-center gap-3 border-b border-n-100 px-2 py-2 transition-colors duration-[120ms]",
        selected ? "bg-a-50" : "hover:bg-n-25",
      )}
    >
      <PriorityIcon priority={item.priority} />

      <span className="w-16 shrink-0 font-mono text-caption text-n-500">{item.reference}</span>

      <span className="min-w-0 flex-1">
        <span className="block truncate font-medium text-n-900">{item.title}</span>

        <span className="mt-0.5 flex items-center gap-1.5 text-caption text-n-500">
          {showProject && item.project && (
            <>
              <span className="truncate">{item.project.name}</span>
              <span aria-hidden>·</span>
            </>
          )}
          {item.subtask_count ? (
            <>
              <span>{item.subtask_count} subtasks</span>
              <span aria-hidden>·</span>
            </>
          ) : null}
          {item.estimate_hours ? (
            <span>{item.estimate_hours}h</span>
          ) : (
            // Unestimated work is flagged, not silently treated as zero: a
            // workload bar built on it would be a lie (docs/02 §11).
            <span className="text-s-active">no estimate</span>
          )}
        </span>
      </span>

      {unaccepted && (
        // "Assigned but not acknowledged" is a distinct state from "in
        // progress", and it is the earliest warning a manager gets.
        <span className="hidden shrink-0 rounded-xs bg-s-active/10 px-1.5 py-0.5 text-micro font-medium text-s-active sm:inline">
          not accepted
        </span>
      )}

      {item.state && (
        <StatusChip
          category={item.state.category}
          label={item.state.label}
          className="hidden w-28 shrink-0 sm:inline-flex"
        />
      )}

      <DueDate value={item.due_at} overdue={item.is_overdue} timeZone={timeZone} />

      <span className="hidden w-16 shrink-0 justify-end sm:flex">
        {item.assignees && item.assignees.length > 0 ? (
          <AvatarStack
            people={item.assignees
              .filter((a) => a.role === "assignee")
              .map((a) => ({ id: a.membership_id, name: a.name ?? "Unknown" }))}
          />
        ) : (
          // Unassigned work is a gap a manager must be able to see, so it is
          // labelled rather than left blank.
          <span className="text-micro text-s-active">unassigned</span>
        )}
      </span>
    </Link>
  );
}

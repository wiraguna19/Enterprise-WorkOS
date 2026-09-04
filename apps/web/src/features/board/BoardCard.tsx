"use client";

import Link from "next/link";
import { Avatar } from "@/components/ui/Avatar";
import { clsx } from "@/lib/clsx";
import { PriorityIcon } from "@/features/work-item/components/PriorityIcon";
import { DueDate } from "@/features/work-item/components/DueDate";
import type { BoardColumn as Column, WorkItem } from "@/features/work-item/types";

/**
 * One card.
 *
 * It is a link AND a drag handle, which are two jobs one element cannot do for
 * a keyboard: Enter on a link follows it. So the card is a link, and the move
 * lives on a handle beside it — a real button, labelled, in the tab order. The
 * pointer can drag the whole card because a pointer has no such ambiguity.
 *
 * The handle is not hidden until hover. A control that appears on hover is a
 * control a touch user and a keyboard user cannot find, and this one is the
 * only way to move a card without a mouse.
 */
export function BoardCard({
  item,
  column,
  timeZone,
  picked,
  moving,
  onPickUp,
  onCancel,
  onReorderTo,
}: {
  item: WorkItem;
  column: Column;
  timeZone: string;
  picked: boolean;
  moving: boolean;
  onPickUp: () => void;
  onCancel: () => void;
  onReorderTo: (direction: -1 | 1) => void;
}) {
  const assignee = item.assignees?.find((a) => a.role === "assignee");

  return (
    <li
      data-item={item.reference}
      draggable={!moving}
      onDragStart={(event) => {
        // Some browsers refuse to start a drag without payload. The reference
        // is also genuinely useful: dragged to a text field it pastes something
        // meaningful rather than "[object Object]".
        event.dataTransfer.setData("text/plain", item.reference);
        event.dataTransfer.effectAllowed = "move";
        onPickUp();
      }}
      onDragEnd={onCancel}
      className={clsx(
        "rounded-sm border bg-n-0 shadow-e1 transition-colors duration-[120ms]",
        picked ? "border-a-500 ring-2 ring-a-500/30" : "border-n-200 hover:border-n-300",
        moving && "opacity-60",
      )}
    >
      <div className="flex items-start gap-1 p-2.5">
        <div className="min-w-0 flex-1">
          {/* Not draggable itself. An anchor is natively draggable, and a
              link drag is a different gesture with its own payload — it would
              start instead of the card's, from the largest target on the card.
              The card is what moves; the link is only how you open it. */}
          <Link href={`/work/${item.reference}`} draggable={false} className="block">
            <p className="mb-1.5 line-clamp-3 text-body-sm font-medium text-n-900">
              {item.title}
            </p>
          </Link>

          <div className="flex items-center gap-2">
            <PriorityIcon priority={item.priority} />
            <span className="font-mono text-micro text-n-500">{item.reference}</span>

            <span className="ml-auto flex items-center gap-2">
              <DueDate value={item.due_at} overdue={item.is_overdue} timeZone={timeZone} />
              {assignee ? (
                <Avatar id={assignee.membership_id} name={assignee.name ?? "?"} size="sm" />
              ) : (
                <span className="text-micro text-s-active">unassigned</span>
              )}
            </span>
          </div>
        </div>

        <button
          type="button"
          data-card={item.reference}
          aria-pressed={picked}
          aria-label={
            picked
              ? `${item.reference} picked up, in ${column.state.label}. Left and right choose a column, space drops, escape cancels.`
              : `Move ${item.reference}, currently in ${column.state.label}`
          }
          disabled={moving}
          onClick={() => (picked ? onCancel() : onPickUp())}
          onKeyDown={(event) => {
            // Reordering inside the column, which the arrow keys along the
            // board cannot express. Held with a modifier so that ↑/↓ still
            // scroll the page for someone who is only reading.
            if (!picked || !event.altKey) return;

            if (event.key === "ArrowUp") {
              event.preventDefault();
              onReorderTo(-1);
            } else if (event.key === "ArrowDown") {
              event.preventDefault();
              onReorderTo(1);
            }
          }}
          className={clsx(
            "-m-1 shrink-0 cursor-grab rounded-sm p-1 text-n-500 hover:bg-n-50 hover:text-n-700",
            "focus:outline-2 focus:outline-offset-1 focus:outline-a-500",
            picked && "bg-a-500/10 text-a-500",
          )}
        >
          <span aria-hidden className="block text-micro leading-none">
            {moving ? "…" : "⠿"}
          </span>
        </button>
      </div>
    </li>
  );
}

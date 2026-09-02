import Link from "next/link";
import { formatDate } from "@/lib/format";
import { clsx } from "@/lib/clsx";
import type { AtRiskItem, RiskReason } from "./types";

/**
 * Where the risk is (docs/08 §3, ADR 0009).
 *
 * Every row says why it is here, in its own words rather than as a colour or a
 * score: "overdue 2 days · blocks 2 items" is something a manager can act on,
 * and 0.72 is not.
 *
 * Nothing here is a card with a number on it. The count in the heading is the
 * only figure on the block, and it exists to say how long the list is.
 */
export function AtRiskList({ items, timeZone }: { items: AtRiskItem[]; timeZone: string }) {
  return (
    <div className="border-t border-n-100">
      {items.map((item) => (
        <Link
          key={item.id}
          href={`/work/${item.reference}`}
          className="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-n-100 px-2 py-2 hover:bg-n-25"
        >
          <span className="w-16 shrink-0 font-mono text-caption text-n-500">{item.reference}</span>

          <span className="min-w-0 flex-1 truncate font-medium text-n-900">{item.title}</span>

          <span className="shrink-0 text-caption text-n-500">
            {item.assignee ?? "unassigned"}
          </span>

          <span className="flex shrink-0 flex-wrap gap-1.5">
            {item.reasons.map((reason) => (
              <Reason key={reason} reason={reason} item={item} timeZone={timeZone} />
            ))}
          </span>
        </Link>
      ))}
    </div>
  );
}

/**
 * A reason, written out with the fact that produced it.
 *
 * "Stalled" alone invites the question the row should already have answered;
 * "no movement in 9 d" is the same word with its evidence attached.
 */
function Reason({
  reason,
  item,
  timeZone,
}: {
  reason: RiskReason;
  item: AtRiskItem;
  timeZone: string;
}) {
  const text: Record<RiskReason, string> = {
    overdue: item.due_at ? `overdue since ${formatDate(item.due_at, timeZone)}` : "overdue",
    blocking: `blocks ${item.blocking_count} ${item.blocking_count === 1 ? "item" : "items"}`,
    unassigned: "unassigned",
    stalled: `no movement in ${item.days_since_move} d`,
    blocked: "blocked",
  };

  return (
    <span
      className={clsx(
        "rounded-full px-1.5 py-0.5 text-micro font-medium",
        reason === "overdue" && "bg-s-danger/10 text-s-danger",
        reason === "blocking" && "bg-s-review/10 text-s-review",
        reason !== "overdue" && reason !== "blocking" && "bg-n-100 text-n-700",
      )}
    >
      {text[reason]}
    </span>
  );
}

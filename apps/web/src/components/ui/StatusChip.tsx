import { clsx } from "@/lib/clsx";

/**
 * A dot plus a label. Never a full-width coloured row — a board where every
 * card is a different colour is unreadable within a week (docs/09 §5).
 *
 * The chip keys off the state CATEGORY, never the state label, which is what
 * lets a customer rename "In Review" to "QA Gate" without breaking anything.
 */
export type StateCategory =
  | "backlog"
  | "todo"
  | "in_progress"
  | "in_review"
  | "blocked"
  | "done"
  | "cancelled";

const TONE: Record<StateCategory, string> = {
  backlog: "bg-s-neutral",
  todo: "bg-s-info",
  in_progress: "bg-s-active",
  in_review: "bg-s-review",
  blocked: "bg-s-danger",
  done: "bg-s-success",
  cancelled: "bg-n-300",
};

export function StatusChip({
  category,
  label,
  className,
}: {
  category: StateCategory;
  label: string;
  className?: string;
}) {
  return (
    <span className={clsx("inline-flex items-center gap-1.5 text-body-sm text-n-700", className)}>
      {/* aria-hidden: the label carries the meaning, the dot is redundant
          reinforcement. Colour is never the sole carrier (docs/09 §2). */}
      <span className={clsx("size-1.5 shrink-0 rounded-full", TONE[category])} aria-hidden />
      {label}
    </span>
  );
}

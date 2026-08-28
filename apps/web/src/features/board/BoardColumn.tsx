import Link from "next/link";
import { clsx } from "@/lib/clsx";
import { Avatar } from "@/components/ui/Avatar";
import { PriorityIcon } from "@/features/work-item/components/PriorityIcon";
import { DueDate } from "@/features/work-item/components/DueDate";
import type { BoardColumn as Column } from "@/features/work-item/types";

const CATEGORY_DOT: Record<string, string> = {
  backlog: "bg-s-neutral",
  todo: "bg-s-info",
  in_progress: "bg-s-active",
  in_review: "bg-s-review",
  blocked: "bg-s-danger",
  done: "bg-s-success",
  cancelled: "bg-n-300",
};

/**
 * A board column (docs/09 §1).
 *
 * The CARDS are cards — a board is the one place where the card metaphor is
 * correct, because each item really is a discrete draggable object. Everything
 * else in this product is a row.
 *
 * The column header carries the count because "how much is stuck in review" is
 * the question a board is read to answer.
 */
export function BoardColumn({ column, timeZone }: { column: Column; timeZone: string }) {
  return (
    <section
      aria-labelledby={`column-${column.state.key}`}
      className="flex w-72 shrink-0 flex-col"
    >
      <header className="flex items-center gap-2 px-1 pb-2">
        <span
          aria-hidden
          className={clsx("size-1.5 rounded-full", CATEGORY_DOT[column.state.category])}
        />
        <h2
          id={`column-${column.state.key}`}
          className="text-body-sm font-semibold text-n-900"
        >
          {column.state.label}
        </h2>
        <span className="text-caption text-n-500 tabular-nums">{column.items.length}</span>
      </header>

      <ol className="flex flex-1 flex-col gap-1.5">
        {column.items.length === 0 ? (
          <li className="rounded-sm border border-dashed border-n-200 px-3 py-6 text-center text-caption text-n-500">
            Nothing here
          </li>
        ) : (
          column.items.map((item) => {
            const assignee = item.assignees?.find((a) => a.role === "assignee");

            return (
              <li key={item.id}>
                <Link
                  href={`/work/${item.reference}`}
                  className="block rounded-sm border border-n-200 bg-n-0 p-2.5 shadow-e1 transition-colors duration-[120ms] hover:border-n-300"
                >
                  <p className="mb-1.5 line-clamp-3 text-body-sm font-medium text-n-900">
                    {item.title}
                  </p>

                  <div className="flex items-center gap-2">
                    <PriorityIcon priority={item.priority} />
                    <span className="font-mono text-micro text-n-500">{item.reference}</span>

                    <span className="ml-auto flex items-center gap-2">
                      <DueDate
                        value={item.due_at}
                        overdue={item.is_overdue}
                        timeZone={timeZone}
                      />
                      {assignee ? (
                        <Avatar
                          id={assignee.membership_id}
                          name={assignee.name ?? "?"}
                          size="sm"
                        />
                      ) : (
                        <span className="text-micro text-s-active">unassigned</span>
                      )}
                    </span>
                  </div>
                </Link>
              </li>
            );
          })
        )}
      </ol>
    </section>
  );
}

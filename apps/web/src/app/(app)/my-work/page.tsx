import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { WorkItemRow } from "@/features/work-item/components/WorkItemRow";
import type { WorkItem } from "@/features/work-item/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { clsx } from "@/lib/clsx";

/**
 * My Work (docs/08 §3).
 *
 * The ORDERING is the design: exceptions first, then commitments, then
 * foresight, then blockers. A person opening this should know what to do next
 * in about three seconds without reading anything twice.
 *
 * Nothing here is a card with a number on it.
 */

const VIEWS = [
  { key: "today", label: "Today" },
  { key: "upcoming", label: "Upcoming" },
  { key: "overdue", label: "Overdue" },
  { key: "assigned", label: "Assigned" },
  { key: "waiting_on_others", label: "Waiting on others" },
  { key: "completed", label: "Completed" },
] as const;

export default async function MyWorkPage({
  searchParams,
}: {
  searchParams: Promise<{ view?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);
  const view = VIEWS.find((v) => v.key === params.view)?.key ?? "today";

  // Fetched in parallel: three sequential awaits would triple the time to
  // first paint for no benefit (docs/07 §2).
  const [{ data: items }, { data: counts }] = await Promise.all([
    api<WorkItem[]>(`/me/work?view=${view}&limit=100`),
    api<Record<string, number>>("/me/work/counts"),
  ]);

  return (
    <div className="space-y-5">
      <PageHeader
        title="My Work"
        description={
          counts.overdue > 0
            ? `${counts.overdue} overdue · ${counts.open} open`
            : `${counts.open} open`
        }
      />

      <nav aria-label="Work views" className="flex gap-1 overflow-x-auto border-b border-n-100">
        {VIEWS.map((v) => {
          const active = v.key === view;
          const badge =
            v.key === "overdue"
              ? counts.overdue
              : v.key === "waiting_on_others"
                ? counts.waiting_on_others
                : 0;

          return (
            <Link
              key={v.key}
              href={`/my-work?view=${v.key}`}
              aria-current={active ? "page" : undefined}
              className={clsx(
                "flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-body transition-colors duration-[120ms]",
                active
                  ? "border-a-500 font-medium text-n-900"
                  : "border-transparent text-n-500 hover:text-n-700",
              )}
            >
              {v.label}
              {badge > 0 && (
                <span
                  className={clsx(
                    "rounded-full px-1.5 text-micro font-semibold",
                    v.key === "overdue" ? "bg-s-danger/10 text-s-danger" : "bg-n-100 text-n-500",
                  )}
                >
                  {badge}
                </span>
              )}
            </Link>
          );
        })}
      </nav>

      {items.length === 0 ? (
        <EmptyState
          title={emptyTitle(view)}
          description={emptyDescription(view)}
        />
      ) : (
        <div>
          {items.map((item) => (
            <WorkItemRow key={item.id} item={item} timeZone={me.user.timezone} />
          ))}
        </div>
      )}
    </div>
  );
}

/**
 * Empty states are written per view, not shared.
 *
 * "No results" tells someone nothing. An empty Overdue tab is GOOD NEWS and
 * should read that way; an empty Today tab means something different again
 * (docs/07 §7).
 */
function emptyTitle(view: string): string {
  return {
    today: "Nothing due today",
    upcoming: "Nothing scheduled in the next two weeks",
    overdue: "Nothing overdue",
    assigned: "No open work assigned to you",
    waiting_on_others: "You are not blocked on anyone",
    completed: "Nothing completed in the last 30 days",
  }[view] ?? "Nothing here";
}

function emptyDescription(view: string): string {
  return {
    today: "Work due today and anything already late appears here first.",
    upcoming: "Work due in the next fortnight will show up here as deadlines are set.",
    overdue: "Everything assigned to you is still within its deadline.",
    assigned: "When someone assigns you work, it appears here and in your inbox.",
    waiting_on_others: "Work you have submitted for review, or that is blocked by another item, collects here.",
    completed: "Work you finish is kept here for a month.",
  }[view] ?? "";
}

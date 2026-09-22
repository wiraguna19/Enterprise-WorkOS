import { notFound } from "next/navigation";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { EmptyState } from "@/components/ui/EmptyState";
import { WorkItemRow } from "@/features/work-item/components/WorkItemRow";
import type { WorkItem } from "@/features/work-item/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * The work sitting in one state category right now (ADR 0010, docs/10).
 *
 * This exists because the bottleneck table has a "sitting there now" column,
 * and a number a user cannot drill into does not ship. It is deliberately a
 * snapshot with no window: the figure it explains is one too.
 *
 * The list comes from the work item endpoint with its own visibility rule
 * applied, so two readers can legitimately see different lists behind the same
 * count — the count is a fact about the organization and the list is a fact
 * about the reader, the same split as every other drill-through here.
 */
const LABELS: Record<string, string> = {
  backlog: "Backlog",
  todo: "Todo",
  in_progress: "In Progress",
  in_review: "In Review",
  blocked: "Blocked",
};

export default async function WaitingPage({
  searchParams,
}: {
  searchParams: Promise<{ category?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  const category = params.category ?? "";
  const label = LABELS[category];

  // Only categories work can WAIT in. `done` and `cancelled` are where work
  // stops, and a queue of finished work is not a queue.
  if (!label) notFound();

  const { data: items } = await api<WorkItem[]>(
    `/work-items?filter[state_category]=${category}&sort=due_at&limit=100`,
  ).catch(() => ({ data: [] as WorkItem[] }));

  return (
    // The product's own width, not this page's: it used to centre itself at a
    // max-width nothing else uses, so arriving here from Flow moved the left
    // edge of everything (ADR 0024).
    <div className="space-y-5">
      <div className="space-y-3">
        {/* The same trail its sibling drill-through already had, instead of a
            hand-drawn back link (ADR 0026). */}
        <Breadcrumb items={[{ label: "Flow", href: "/reports" }, { label: `Waiting in ${label}` }]} />

        <PageHeader
          title={`Waiting in ${label}`}
          description={`${items.length} ${items.length === 1 ? "item" : "items"} you can see`}
        />
      </div>

      <PageBody>
        {items.length === 0 ? (
          <EmptyState
            title="Nothing here"
            description={`No work you have access to is sitting in ${label} right now.`}
          />
        ) : (
          <Panel
            id="waiting"
            title={`In ${label}`}
            description="Ordered by due date."
            footer={
              <p className="max-w-prose text-caption text-n-500">
                A snapshot. The count on the Flow page counts every item in this category; this
                list is the part you have access to.
              </p>
            }
            bleed
          >
            {items.map((item) => (
              <WorkItemRow key={item.id} item={item} timeZone={me.user.timezone} />
            ))}
          </Panel>
        )}
      </PageBody>
    </div>
  );
}

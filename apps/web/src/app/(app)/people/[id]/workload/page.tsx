import Link from "next/link";
import { notFound } from "next/navigation";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { WorkloadBar } from "@/components/ui/WorkloadBar";
import type { PersonDetail, WorkloadItem, WorkloadItemsMeta } from "@/features/people/types";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { formatDateTime } from "@/lib/format";

/**
 * The work behind a person's committed hours.
 *
 * Phase 6's first house rule: **a number must be able to show its work.** The
 * workload bar has shown the hours since Phase 6 and `/people/{id}/workload/items`
 * has been able to explain them for just as long, with nothing calling it — so
 * the one figure a staffing decision gets made from was the one figure nobody
 * could check. That is the house rule's own counter-example, sitting in the
 * product for a phase.
 *
 * Two things make this list an explanation rather than another list of work:
 *
 *   - **`share_hours`, not `estimate_hours`.** An item spanning three weeks
 *     contributes a slice to each. Printing the estimate would give a list
 *     whose numbers do not add up to the bar above it, which is worse than no
 *     drill-through: it invites the reader to trust the wrong arithmetic
 *     (ADR 0009).
 *   - **The residue is stated.** Work the reader may not see, and committed
 *     work with no dates that lands in no week at all, are both counted and
 *     said out loud. A total that does not reconcile with its list reads as a
 *     bug in the product; a total that says why it does not reads as the truth
 *     (ADR 0008).
 *
 * The bar is re-rendered from the same meta the API folded the list from, not
 * recomputed here. A second implementation of "committed" is how a figure and
 * its evidence end up arguing with each other.
 */
export default async function WorkloadItemsPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ week?: string }>;
}) {
  const [me, { id }, query] = await Promise.all([requireUser(), params, searchParams]);

  // The week travels as the API spells it and is not parsed here: it anchors a
  // week rather than naming one, and the API's `startOfWeek` is the only
  // definition of which week that is.
  const week = query.week ? `?week=${encodeURIComponent(query.week)}` : "";

  let items: WorkloadItem[];
  let meta: WorkloadItemsMeta;
  let person: PersonDetail;

  try {
    const [breakdown, profile] = await Promise.all([
      api<WorkloadItem[]>(`/people/${id}/workload/items${week}`),
      api<PersonDetail>(`/people/${id}`),
    ]);

    items = breakdown.data;
    meta = breakdown.meta as unknown as WorkloadItemsMeta;
    person = profile.data;
  } catch (error) {
    // 403 folded into 404 with it: whose workload you may read is a policy
    // decision, and confirming that a person exists by refusing differently is
    // the leak that separation is meant to prevent (docs/05 §3).
    if (error instanceof ApiRequestError && (error.status === 404 || error.status === 403)) {
      notFound();
    }

    throw error;
  }

  // Summed from what is ON SCREEN, not read from the meta. The two differ by
  // exactly the hidden and undated work described below, and showing the
  // difference is the point — a footer that silently printed `committed_hours`
  // would make the residue invisible again.
  const listed = items.reduce((total, item) => total + (item.share_hours ?? 0), 0);

  return (
    <div className="space-y-5">
      <div className="space-y-3">
        <Link href={`/people/${id}`} className="text-body-sm text-n-500 hover:text-a-700">
          ← {person.name}
        </Link>

        <PageHeader
          title="Committed work"
          description={`Week of ${meta.week_start}`}
        />
      </div>

      <WorkloadBar
        committedHours={meta.committed_hours}
        capacityHours={meta.capacity_hours}
        itemCount={meta.item_count}
        unestimatedCount={meta.unestimated_count}
      />

      {items.length === 0 ? (
        <EmptyState
          title="Nothing committed this week"
          description="Work lands in a week through its start and due dates. Work without either is committed but unplaced — it appears in the note below rather than in this list."
        />
      ) : (
        <ul className="border-y border-n-100">
          {items.map((item) => (
            <li key={item.id}>
              <Link
                href={`/work/${item.reference}`}
                className="flex items-baseline gap-3 border-b border-n-100 px-2 py-2 last:border-b-0 hover:bg-n-25"
              >
                <span className="w-16 shrink-0 font-mono text-caption text-n-500">
                  {item.reference}
                </span>

                <span className="min-w-0 flex-1 truncate font-medium text-n-900">
                  {item.title}
                </span>

                <span className="shrink-0 text-caption tabular-nums text-n-500">
                  {item.due_at ? formatDateTime(item.due_at, me.user.timezone) : "no due date"}
                </span>

                {/* The contribution, flagged where it is the organization's
                    default rather than anyone's estimate. Marking it on the ROW
                    is what lets a manager tell "this person has 32 committed
                    hours" from "six items nobody has estimated". */}
                <span
                  className="w-20 shrink-0 text-right text-caption tabular-nums text-n-700"
                  title={
                    item.counted_at_default
                      ? "Counted at the organization's default estimate."
                      : undefined
                  }
                >
                  {item.share_hours === null ? "—" : `${item.share_hours} h`}
                  {item.counted_at_default && <span className="ml-1 text-s-active">*</span>}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      <div className="max-w-[72ch] space-y-1.5 text-caption">
        <p className="tabular-nums text-n-700">
          {Math.round(listed * 100) / 100} h listed of {meta.committed_hours} h committed.
        </p>

        {meta.hidden_count > 0 && (
          <p className="text-s-active">
            {meta.hidden_count} further{" "}
            {meta.hidden_count === 1 ? "item is" : "items are"} counted in this total but not
            listed — {meta.hidden_count === 1 ? "it is" : "they are"} work you do not have access
            to.
          </p>
        )}

        {meta.undated_count > 0 && (
          <p className="text-s-active">
            {meta.undated_count} committed{" "}
            {meta.undated_count === 1 ? "item has" : "items have"} no dates, so{" "}
            {meta.undated_count === 1 ? "it lands" : "they land"} in no week and{" "}
            {meta.undated_count === 1 ? "is" : "are"} in neither figure above.
          </p>
        )}

        {meta.unestimated_count > 0 && (
          <p className="text-n-500">
            * Counted at the organization&rsquo;s default of {meta.default_estimate_hours} h.{" "}
            {meta.unestimated_count} of these {meta.unestimated_count === 1 ? "item is" : "items are"}{" "}
            unestimated.
          </p>
        )}

        {meta.time_off_hours === null && (
          <p className="text-n-500">Capacity is not adjusted for leave.</p>
        )}
      </div>
    </div>
  );
}

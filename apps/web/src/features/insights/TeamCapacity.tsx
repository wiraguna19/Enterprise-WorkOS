import Link from "next/link";
import { WorkloadBar } from "@/components/ui/WorkloadBar";
import type { Workload } from "@/features/people/types";

type Row = Workload & { name: string | null };

/**
 * The capacity block on Manager Home (docs/08 §3, docs/02 §11).
 *
 * Rows, never one team number: an over-committed person beside an idle one
 * averages to "fine", which is the single reading of this data that helps
 * nobody. Most-committed first, because "who is drowning" should not be
 * somewhere in the middle of an alphabetical list.
 *
 * This is operational capacity signal, not a performance ranking. Nothing here
 * is a score, the order is by load rather than by output, and the caveat that
 * capacity is unadjusted for leave travels with the block — the same rule the
 * personal workload panel follows.
 */
export function TeamCapacity({ rows, withheld }: { rows: Row[]; withheld: number }) {
  return (
    <div className="space-y-3">
      {rows.map((row) => (
        <div key={row.membership_id} className="space-y-1">
          <div className="flex items-baseline justify-between gap-3">
            <Link
              href={`/people/${row.membership_id}`}
              className="text-body-sm font-medium text-n-900 hover:text-a-700"
            >
              {row.name ?? "Unnamed"}
            </Link>
            {row.undated_count > 0 && (
              <span className="text-caption text-s-active">
                {row.undated_count} undated
              </span>
            )}
          </div>

          <WorkloadBar
            committedHours={row.committed_hours}
            capacityHours={row.capacity_hours}
            itemCount={row.item_count}
            unestimatedCount={row.unestimated_count}
          />
        </div>
      ))}

      <p className="text-caption text-n-500">
        Capacity is not adjusted for leave — nothing in the system records it yet. Unestimated
        work is counted at the organization&rsquo;s default estimate.
        {withheld > 0 && ` ${withheld} of your reports are not shown: you cannot see their workload.`}
      </p>
    </div>
  );
}

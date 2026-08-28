import { WorkloadBar } from "@/components/ui/WorkloadBar";
import type { Workload } from "./types";

/**
 * One person's week (docs/02 §11).
 *
 * The bar is never shown alone. Every caveat the number carries is printed
 * beside it, because this is operational capacity signal that someone will make
 * a staffing decision from — and a decision made on a number whose assumptions
 * are invisible is a decision made on the wrong number.
 *
 * Three caveats, all from the API rather than invented here: work counted at
 * the organization's default estimate, committed work with no dates that lands
 * in no week at all, and a capacity that has not been reduced for leave because
 * nothing in the system records leave yet.
 */
export function WorkloadPanel({ workload }: { workload: Workload }) {
  return (
    <div className="space-y-1.5">
      <WorkloadBar
        committedHours={workload.committed_hours}
        capacityHours={workload.capacity_hours}
        itemCount={workload.item_count}
        unestimatedCount={workload.unestimated_count}
      />

      <p className="text-caption text-n-500">
        Week of {workload.week_start}. Unestimated work is counted at{" "}
        {workload.default_estimate_hours} h.
        {workload.undated_count > 0 && (
          <>
            {" "}
            <span className="text-s-active">
              {workload.undated_count} committed{" "}
              {workload.undated_count === 1 ? "item has" : "items have"} no dates
            </span>{" "}
            and {workload.undated_count === 1 ? "is" : "are"} not in this total.
          </>
        )}{" "}
        {workload.time_off_hours === null && "Capacity is not adjusted for leave."}
      </p>
    </div>
  );
}

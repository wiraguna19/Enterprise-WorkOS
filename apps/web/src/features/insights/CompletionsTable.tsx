import Link from "next/link";
import { formatDateTime } from "@/lib/format";
import { formatCycleHours } from "./format";
import type { FlowCompletion } from "./types";

/**
 * The records behind a throughput figure (docs/10, Phase 6 exit criteria).
 *
 * Ordered as the API returns them — most recent completion first — because the
 * question that brings someone here is "what went out", and the answer starts
 * at the top.
 *
 * Every row links to the item itself. A drill-through that ends in another
 * summary is not a drill-through; the point is to arrive at the record the
 * number was computed from and be able to disagree with it.
 */
export function CompletionsTable({
  completions,
  timeZone,
}: {
  completions: FlowCompletion[];
  timeZone: string;
}) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[34rem] border-collapse text-body-sm">
        <caption className="sr-only">Completed work items, most recent first</caption>

        <thead>
          <tr className="border-b border-n-200 text-left">
            <Th>Item</Th>
            <Th>Project</Th>
            <Th className="text-right">Completed</Th>
            <Th className="text-right">Cycle time</Th>
          </tr>
        </thead>

        <tbody>
          {completions.map((item) => (
            <tr key={item.id} className="border-b border-n-100 hover:bg-n-25">
              <Td>
                <Link href={`/work/${item.reference}`} className="group flex items-baseline gap-2">
                  <span className="w-16 shrink-0 font-mono text-caption text-n-500">
                    {item.reference}
                  </span>
                  <span className="min-w-0 truncate font-medium text-n-900 group-hover:underline">
                    {item.title}
                  </span>
                </Link>
              </Td>
              <Td className="whitespace-nowrap text-n-500">{item.project ?? "—"}</Td>
              <Td className="whitespace-nowrap text-right tabular-nums text-n-700">
                {formatDateTime(item.completed_at, timeZone)}
              </Td>
              <Td className="text-right tabular-nums">
                {item.cycle_time_hours === null ? (
                  // Named rather than dashed away: this item is in the
                  // throughput and out of every percentile, and a reader adding
                  // the column up deserves to know which rows did not count.
                  <span className="text-caption text-s-active">never started</span>
                ) : (
                  formatCycleHours(item.cycle_time_hours)
                )}
              </Td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Th({ children, className = "" }: { children: React.ReactNode; className?: string }) {
  return (
    <th
      scope="col"
      className={`px-3 py-2 text-micro font-semibold uppercase tracking-[0.04em] text-n-500 ${className}`}
    >
      {children}
    </th>
  );
}

function Td({ children, className = "" }: { children: React.ReactNode; className?: string }) {
  return <td className={`px-3 py-2 align-middle ${className}`}>{children}</td>;
}

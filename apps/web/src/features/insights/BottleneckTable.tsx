import Link from "next/link";
import { formatCycleHours } from "./format";
import type { Bottleneck } from "./types";

/**
 * Where work waited (docs/08 §3, ADR 0010).
 *
 * Ordered by the wait, not by the queue. A backlog with two hundred items in it
 * is not a bottleneck — the backlog is where work is supposed to wait; a review
 * step with a four-day median is.
 *
 * Two figures that must not be confused, so they are labelled differently: the
 * median is history (waits that finished in this window), and "waiting now" is
 * a snapshot that changes when time passes rather than when work happens.
 */
const LABELS: Record<string, string> = {
  backlog: "Backlog",
  todo: "Todo",
  in_progress: "In Progress",
  in_review: "In Review",
  blocked: "Blocked",
  done: "Done",
  cancelled: "Cancelled",
};

export function BottleneckTable({ rows }: { rows: Bottleneck[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[30rem] border-collapse text-body-sm">
        <caption className="sr-only">Median time spent in each state category</caption>

        <thead>
          <tr className="border-b border-n-200 text-left">
            <Th>Category</Th>
            <Th className="text-right">Median wait</Th>
            <Th className="text-right">85th percentile</Th>
            <Th className="text-right">Waits measured</Th>
            <Th className="text-right">Sitting there now</Th>
          </tr>
        </thead>

        <tbody>
          {rows.map((row) => (
            <tr key={row.category} className="border-b border-n-100">
              <Td className="whitespace-nowrap text-n-900">
                {LABELS[row.category] ?? row.category}
              </Td>
              <Td className="text-right tabular-nums">{formatCycleHours(row.median_hours)}</Td>
              <Td className="text-right tabular-nums">{formatCycleHours(row.p85_hours)}</Td>
              <Td className="text-right tabular-nums text-n-500">{row.steps}</Td>
              <Td className="text-right tabular-nums">
                {row.waiting_now === 0 ? (
                  <span className="text-n-500">0</span>
                ) : (
                  <Link
                    href={`/reports/waiting?category=${row.category}`}
                    className="text-a-700 hover:underline"
                    aria-label={`${row.waiting_now} items currently in ${LABELS[row.category] ?? row.category}`}
                  >
                    {row.waiting_now}
                  </Link>
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

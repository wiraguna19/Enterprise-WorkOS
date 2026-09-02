import { formatDate } from "@/lib/format";
import type { Flow } from "./types";

/**
 * How work flowed, week by week (ADR 0007, docs/08 §3).
 *
 * A table of numbers rather than a chart, for twelve rows: the questions people
 * bring here are "how many shipped last week" and "is the tail getting longer",
 * and both are read directly off figures. A chart would be a picture of twelve
 * numbers, and the numbers would then need printing anyway.
 *
 * Every figure carries its sample size. A quiet week and a fast week look
 * identical in a percentile until you can see how many items it was computed
 * from — and a p85 over two completions is not a fact about the process.
 */
export function FlowTable({ flow, timeZone }: { flow: Flow; timeZone: string }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[34rem] border-collapse text-body-sm">
        <caption className="sr-only">
          Throughput and cycle time by week, {flow.from} to {flow.to}
        </caption>

        <thead>
          <tr className="border-b border-n-200 text-left">
            <Th>Week of</Th>
            <Th className="text-right">Completed</Th>
            <Th className="text-right">Median</Th>
            <Th className="text-right">85th percentile</Th>
            <Th className="text-right">Measured</Th>
          </tr>
        </thead>

        <tbody>
          {flow.weeks.map((week) => (
            <tr key={week.week_start} className="border-b border-n-100">
              <Td className="whitespace-nowrap text-n-700">
                {formatDate(week.week_start, timeZone)}
              </Td>
              <Td className="text-right tabular-nums text-n-900">{week.throughput}</Td>
              <Td className="text-right tabular-nums">{hours(week.cycle_time_p50_hours)}</Td>
              <Td className="text-right tabular-nums">{hours(week.cycle_time_p85_hours)}</Td>
              <Td className="text-right tabular-nums text-n-500">
                {week.measured}
                {week.measured < week.throughput && (
                  <span className="ml-1 text-caption text-s-active">
                    of {week.throughput}
                  </span>
                )}
              </Td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/**
 * Hours below two days, days above.
 *
 * "62 h" is a number somebody has to divide before it means anything, and the
 * dividing is where a reader stops reading.
 */
function hours(value: number | null): string {
  if (value === null) return "—";
  if (value < 48) return `${Math.round(value)} h`;

  return `${(value / 24).toFixed(1)} d`;
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

import Link from "next/link";
import type { FlowDepartment } from "./types";

/**
 * Which departments delivered the work (docs/08 §3, ADR 0010).
 *
 * Departments, never people. `docs/02` §11 rules out reducing individual
 * performance to a ranked number, and "throughput by assignee" is that number
 * under a neutral name — a department is a unit of capacity and budget, and a
 * person is not.
 *
 * The rows sum to the throughput above them, which is why work with no
 * department gets a row of its own rather than being dropped: rows that do not
 * add up read as an arithmetic error and are the same defect `hidden_count`
 * exists to prevent on every drill-through here.
 */
export function DepartmentSplit({
  departments,
  total,
  window,
}: {
  departments: FlowDepartment[];
  total: number;
  window: { from: string; to: string };
}) {
  return (
    <ul className="space-y-1.5">
      {departments.map((row) => {
        const share = total === 0 ? 0 : row.throughput / total;
        const href =
          row.department_id === null
            ? null
            : `/reports/completions?from=${window.from}&to=${window.to}&department_id=${row.department_id}`;

        return (
          <li key={row.department_id ?? "none"} className="space-y-0.5">
            <div className="flex items-baseline justify-between gap-3 text-body-sm">
              {href ? (
                <Link href={href} className="text-n-900 hover:text-a-700 hover:underline">
                  {row.name}
                </Link>
              ) : (
                // No link: this row is work with no project, which is private
                // to the people involved in it (ADR 0004). The count is a fact
                // about the organization; a list of those items is not one this
                // page can offer.
                <span className="text-n-700">No department</span>
              )}

              <span className="shrink-0 tabular-nums text-n-500">
                {row.throughput}
                {row.late > 0 && <span className="text-s-active"> · {row.late} late</span>}
              </span>
            </div>

            <div
              className="h-1.5 rounded-full bg-n-100"
              role="presentation"
            >
              <div
                className="h-full rounded-full bg-a-500"
                style={{ width: `${Math.round(share * 100)}%` }}
              />
            </div>
          </li>
        );
      })}
    </ul>
  );
}

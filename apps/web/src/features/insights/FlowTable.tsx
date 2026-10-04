import Link from "next/link";
import { formatDate } from "@/lib/format";
import { formatCycleHours, weekEnd } from "./format";
import type { Flow } from "./types";
import { type Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

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
 *
 * The throughput count is a link, because it is the figure with records behind
 * it: docs/10 requires every number to open onto what it was computed from. The
 * percentiles are not linked separately — they are folded from the same
 * completions, and a second link to the same list would only suggest otherwise.
 */
export function FlowTable({
  flow,
  timeZone,
  locale = "en",
}: {
  flow: Flow;
  timeZone: string;
  locale?: Locale;
}) {
  const t = translator(locale);

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[34rem] border-collapse text-body-sm">
        <caption className="sr-only">
          {t("flow.table.caption", { from: flow.from, to: flow.to })}
        </caption>

        <thead>
          <tr className="border-b border-n-200 text-left">
            <Th>{t("flow.table.week")}</Th>
            <Th className="text-right">{t("flow.table.completed")}</Th>
            <Th className="text-right">{t("flow.table.median")}</Th>
            <Th className="text-right">{t("flow.p85")}</Th>
            <Th className="text-right">{t("flow.measured")}</Th>
          </tr>
        </thead>

        <tbody>
          {flow.weeks.map((week) => (
            <tr key={week.week_start} className="border-b border-n-100 hover:bg-n-25">
              <Td className="whitespace-nowrap text-n-700">
                {formatDate(week.week_start, timeZone, locale)}
              </Td>
              <Td className="text-right tabular-nums text-n-900">
                {week.throughput === 0 ? (
                  0
                ) : (
                  <Link
                    href={`/reports/completions?from=${week.week_start}&to=${weekEnd(
                      week.week_start,
                    )}`}
                    aria-label={t("flow.table.weekLink", {
                      count: week.throughput,
                      week: formatDate(week.week_start, timeZone, locale),
                    })}
                    className="text-a-700 hover:underline"
                  >
                    {week.throughput}
                  </Link>
                )}
              </Td>
              <Td className="text-right tabular-nums">
                {formatCycleHours(week.cycle_time_p50_hours, locale)}
              </Td>
              <Td className="text-right tabular-nums">
                {formatCycleHours(week.cycle_time_p85_hours, locale)}
              </Td>
              <Td className="text-right tabular-nums text-n-500">
                {week.measured}
                {week.measured < week.throughput && (
                  <span className="ml-1 text-caption text-s-active">
                    {t("flow.of", { count: week.throughput })}
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

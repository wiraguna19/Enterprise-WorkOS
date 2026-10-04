import Link from "next/link";
import { formatCycleHours } from "./format";
import type { Bottleneck } from "./types";
import { type Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import type { MessageKey } from "@/i18n/messages/en";

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
const CATEGORIES = new Set([
  "backlog",
  "todo",
  "in_progress",
  "in_review",
  "blocked",
  "done",
  "cancelled",
]);

export function BottleneckTable({ rows, locale = "en" }: { rows: Bottleneck[]; locale?: Locale }) {
  const t = translator(locale);
  const label = (category: string): string =>
    CATEGORIES.has(category) ? t(`stateCat.${category}` as MessageKey) : category;

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[30rem] border-collapse text-body-sm">
        <caption className="sr-only">{t("bneck.caption")}</caption>

        <thead>
          <tr className="border-b border-n-200 text-left">
            <Th>{t("bneck.category")}</Th>
            <Th className="text-right">{t("bneck.median")}</Th>
            <Th className="text-right">{t("flow.p85")}</Th>
            <Th className="text-right">{t("bneck.steps")}</Th>
            <Th className="text-right">{t("bneck.now")}</Th>
          </tr>
        </thead>

        <tbody>
          {rows.map((row) => (
            <tr key={row.category} className="border-b border-n-100">
              <Td className="whitespace-nowrap text-n-900">
                {label(row.category)}
              </Td>
              <Td className="text-right tabular-nums">{formatCycleHours(row.median_hours, locale)}</Td>
              <Td className="text-right tabular-nums">{formatCycleHours(row.p85_hours, locale)}</Td>
              <Td className="text-right tabular-nums text-n-500">{row.steps}</Td>
              <Td className="text-right tabular-nums">
                {row.waiting_now === 0 ? (
                  <span className="text-n-500">0</span>
                ) : (
                  <Link
                    href={`/reports/waiting?category=${row.category}`}
                    className="text-a-700 hover:underline"
                    aria-label={t("bneck.nowLink", { count: row.waiting_now, category: label(row.category) })}
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

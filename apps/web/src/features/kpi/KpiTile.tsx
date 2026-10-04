import Link from "next/link";
import { Badge } from "@/components/ui/Badge";
import type { Locale } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import type { Translator } from "@/i18n/translate";
import { formatKpiValue, periodLabel, STATUS_TONE, statusLabel, targetSentence } from "./format";
import { Sparkline } from "./Sparkline";
import type { Kpi } from "./types";

/**
 * One KPI at a glance: the number this period, the target in words, the status
 * in words, and the recent trend (ADR 0062).
 *
 * The number leads because it is the answer; the status badge says whether it
 * is good, so nobody has to do the comparison in their head; the sparkline is
 * last, for the reader who wants to know whether it is getting better.
 */
export function KpiTile({ kpi, t, locale }: { kpi: Kpi; t: Translator; locale: Locale }) {
  const headingId = `kpi-${kpi.id}`;
  const current = kpi.current;

  return (
    <article aria-labelledby={headingId} className="flex flex-col gap-2 rounded-xl border border-n-300 bg-n-0 p-4">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 id={headingId} className="truncate text-body font-semibold text-n-900">
            <Link href={`/kpis/${kpi.id}`} className="hover:text-a-700 hover:underline">
              {kpi.name}
            </Link>
          </h3>
          <p className="truncate text-caption text-n-500">
            {t(`kpi.subject.${kpi.subject.type}` as MessageKey, { name: kpi.subject.name ?? "—" })}
          </p>
        </div>
        <Badge tone={STATUS_TONE[current.status]}>{statusLabel(current.status, t)}</Badge>
      </div>

      <div className="flex items-end justify-between gap-3">
        <div>
          <p className="text-h1 font-semibold tabular-nums text-n-900">
            {formatKpiValue(current.value, kpi.unit, t, locale)}
          </p>
          <p className="text-caption text-n-500">
            {periodLabel(kpi.period, current.period_start, t, locale)}
            {current.partial && ` · ${t("kpi.soFar")}`}
          </p>
        </div>
        <Sparkline
          history={kpi.history}
          target={kpi.target}
          labels={kpi.history.map(
            (point) =>
              `${periodLabel(kpi.period, point.period_start, t, locale)}: ${formatKpiValue(point.value, kpi.unit, t, locale)}`,
          )}
        />
      </div>

      <p className="text-caption text-n-700">{targetSentence(kpi, t, locale)}</p>
    </article>
  );
}

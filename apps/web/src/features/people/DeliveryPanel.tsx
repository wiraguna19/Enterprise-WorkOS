import Link from "next/link";
import { Panel } from "@/components/ui/Panel";
import { Sparkline } from "@/features/kpi/Sparkline";
import { INTL_TAG, type Locale } from "@/i18n/config";
import type { Translator } from "@/i18n/translate";
import type { Delivery } from "./delivery";

/**
 * What a person finished in the last four weeks, and how much was on time
 * (ADR 0062, "Delivery without a KPI").
 *
 * Plain numbers: no target, no status, no comparison. The on-time share always
 * carries its denominator, because "100%" of one dated item and of forty are
 * not the same sentence. Both figures open the items behind them.
 *
 * Rendered only when the API answered — for the person and the people above
 * them in the reporting line, and nobody else.
 */
export function DeliveryPanel({
  membershipId,
  delivery,
  self,
  t,
  locale,
}: {
  membershipId: string;
  delivery: Delivery;
  self: boolean;
  t: Translator;
  locale: Locale;
}) {
  const { summary, weeks } = delivery;
  const href = `/people/${membershipId}/delivery?from=${summary.from}&to=${summary.to}`;
  const week = (start: string) =>
    new Intl.DateTimeFormat(INTL_TAG[locale], { day: "numeric", month: "short", timeZone: "UTC" }).format(
      new Date(`${start}T00:00:00Z`),
    );

  return (
    <Panel
      id="delivery"
      title={t("delivery.title")}
      description={self ? t("delivery.descriptionSelf") : t("delivery.description")}
    >
      <div className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <Link href={href} className="group">
          <span className="block text-h1 font-semibold tabular-nums text-n-900 group-hover:underline">
            {summary.finished}
          </span>
          <span className="text-caption text-n-500">{t("delivery.finished")}</span>
        </Link>

        <Link href={href} className="group">
          <span className="block text-h1 font-semibold tabular-nums text-n-900 group-hover:underline">
            {summary.on_time_rate === null ? "—" : `${summary.on_time_rate}%`}
          </span>
          <span className="text-caption text-n-500">
            {summary.dated === 0
              ? t("delivery.noDated")
              : t("delivery.onTime", { onTime: summary.on_time, dated: summary.dated })}
          </span>
        </Link>

        <div>
          <Sparkline
            history={weeks.map((w) => ({
              period_start: w.period_start,
              period_end: w.period_start,
              partial: w.partial,
              value: w.finished,
              status: "no_data",
              note: null,
            }))}
            // No target here: the reference line sits at the trend's own
            // average, which says "a usual week" rather than "the goal".
            target={weeks.reduce((sum, w) => sum + w.finished, 0) / Math.max(weeks.length, 1)}
            labels={weeks.map((w) => t("delivery.weekPoint", { week: week(w.period_start), count: w.finished }))}
          />
          <span className="block text-caption text-n-500">{t("delivery.trend")}</span>
        </div>
      </div>

      <p className="mt-3 text-caption text-n-500">{t("delivery.footnote")}</p>
    </Panel>
  );
}

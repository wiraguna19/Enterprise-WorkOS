import type { Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { clsx } from "@/lib/clsx";

/**
 * Always numeric, always with the item count, and unestimated work flagged.
 *
 * A bar without its underlying number invites false confidence, and a bar built
 * on unestimated work is a lie — so the component refuses to hide either
 * (docs/02 §11, docs/09 §5).
 *
 * Over-commitment is shown as over 100%, never clamped: clamping hides exactly
 * the case a manager needs to see.
 */
export function WorkloadBar({
  committedHours,
  capacityHours,
  itemCount,
  unestimatedCount = 0,
  locale = "en",
}: {
  committedHours: number;
  capacityHours: number;
  itemCount: number;
  unestimatedCount?: number;
  /** English unless the screen around it has been translated (ADR 0060). */
  locale?: Locale;
}) {
  const t = translator(locale);
  const utilization = capacityHours > 0 ? committedHours / capacityHours : 0;
  const filled = Math.min(utilization, 1) * 100;
  const over = utilization > 1;

  const tone = over
    ? "bg-s-danger"
    : utilization >= 0.85
      ? "bg-s-active"
      : "bg-a-500";

  return (
    <div className="flex items-center gap-3">
      <div
        className="h-1.5 w-32 shrink-0 overflow-hidden rounded-full bg-n-100"
        role="meter"
        aria-valuenow={Math.round(utilization * 100)}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={t("workload.percent", { percent: Math.round(utilization * 100) })}
      >
        <div className={clsx("h-full rounded-full", tone)} style={{ width: `${filled}%` }} />
      </div>

      <span className="text-body-sm text-n-700">
        {t("workload.hours", { committed: committedHours, capacity: capacityHours })}
        {over && <span className="ml-1 font-medium text-s-danger">{t("workload.over")}</span>}
      </span>

      <span className="text-caption text-n-500">
        {t.plural("workload.items", itemCount)}
        {unestimatedCount > 0 && (
          <span className="ml-1 text-s-active">· {t("workload.unestimated", { count: unestimatedCount })}</span>
        )}
      </span>
    </div>
  );
}

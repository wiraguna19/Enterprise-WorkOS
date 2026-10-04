import { INTL_TAG, type Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

/**
 * Cycle time, printed.
 *
 * Hours below two days, days above: "62 h" is a number the reader has to divide
 * before it means anything, and the dividing is where they stop reading. Shared
 * rather than repeated, so the figure in the headline, the figure in the weekly
 * table and the figure on the drill-through cannot drift into three formats of
 * the same duration.
 */
export function formatCycleHours(value: number | null, locale: Locale = "en"): string {
  if (value === null) return "—";

  const t = translator(locale);

  if (value < 48) return t("dur.hours", { n: Math.round(value) });

  // The decimal separator is the reader's: "1,5 h" in Indonesian, "1.5 d" here.
  const days = new Intl.NumberFormat(INTL_TAG[locale], {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
  }).format(value / 24);

  return t("dur.days", { n: days });
}

/**
 * The window a weekly row covers, as the completions endpoint wants it.
 *
 * The row is grouped by `startOfWeek` on the API side, so the drill-through
 * window is that Monday through the Sunday after it — six days, not seven. An
 * off-by-one here shows up as a drill-through whose count does not match the
 * row that opened it, which is precisely the failure docs/10 is guarding
 * against.
 */
export function weekEnd(weekStart: string): string {
  const end = new Date(`${weekStart}T00:00:00Z`);
  end.setUTCDate(end.getUTCDate() + 6);

  return end.toISOString().slice(0, 10);
}

import { INTL_TAG, type Locale } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import type { Translator } from "@/i18n/translate";
import type { Kpi, KpiStatus } from "./types";

/** A value with its unit: "12 items", "38.5 h", "92.3%", "4 releases". */
export function formatKpiValue(value: number | null, unit: string, t: Translator, locale: Locale): string {
  if (value === null) return "—";

  const number = new Intl.NumberFormat(INTL_TAG[locale], { maximumFractionDigits: 1 }).format(value);

  switch (unit) {
    case "items":
      return t("kpi.unit.items", { value: number });
    case "hours":
      return t("kpi.unit.hours", { value: number });
    case "percent":
      return t("kpi.unit.percent", { value: number });
    default:
      return unit === "" ? number : `${number} ${unit}`;
  }
}

/** Status words and badge tones. A status is never shown by colour alone. */
export const STATUS_TONE: Record<KpiStatus, "success" | "warning" | "danger" | "neutral"> = {
  on_track: "success",
  at_risk: "warning",
  off_track: "danger",
  no_data: "neutral",
};

export function statusLabel(status: KpiStatus, t: Translator): string {
  return t(`kpi.status.${status}` as MessageKey);
}

export function sourceLabel(source: string, t: Translator): string {
  return t(`kpi.source.${source}` as MessageKey);
}

/** "Week of 5 Oct 2026", "October 2026", "Q4 2026". */
export function periodLabel(kind: Kpi["period"], start: string, t: Translator, locale: Locale): string {
  const date = new Date(`${start}T00:00:00Z`);

  if (kind === "week") {
    return t("kpi.period.weekOf", {
      date: new Intl.DateTimeFormat(INTL_TAG[locale], { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" }).format(date),
    });
  }

  if (kind === "month") {
    return new Intl.DateTimeFormat(INTL_TAG[locale], { month: "long", year: "numeric", timeZone: "UTC" }).format(date);
  }

  return t("kpi.period.quarter", { quarter: Math.floor(date.getUTCMonth() / 3) + 1, year: date.getUTCFullYear() });
}

/** The target in words: "Target: at least 4 releases a month". */
export function targetSentence(kpi: Kpi, t: Translator, locale: Locale): string {
  return t(kpi.direction === "higher" ? "kpi.target.atLeast" : "kpi.target.atMost", {
    value: formatKpiValue(kpi.target, kpi.unit, t, locale),
    period: t(`kpi.per.${kpi.period}` as MessageKey),
  });
}

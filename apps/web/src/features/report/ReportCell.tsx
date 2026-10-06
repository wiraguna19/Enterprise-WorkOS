import Link from "next/link";
import { Badge } from "@/components/ui/Badge";
import type { Locale } from "@/i18n/config";
import { INTL_TAG } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import { translator } from "@/i18n/translate";
import { formatDate, formatDateTime, formatHours } from "@/lib/format";

/**
 * One report cell, read for a person rather than printed for a file.
 *
 * The API sends a report as columns and rows of scalars — the same rows the
 * export writes — and the page printed them as they came: a Postgres timestamp
 * in UTC with microseconds, `true`/`false`, a utilization of `0.009`. The
 * VALUES are unchanged here (ADR 0011: a report computes nothing of its own);
 * only how each one is shown, decided by its column's name.
 */
export type Cell = string | number | boolean | null;

/** Columns whose numbers line up on the right. */
export function isNumeric(column: string): boolean {
  return column.endsWith("_hours") || column.endsWith("_count") || column === "utilization";
}

/** "2026-10-06 01:17:50.21076+00" is not something every engine parses. */
function instant(value: string): string {
  return value.replace(" ", "T").replace(/([+-]\d{2})$/, "$1:00");
}

export function columnLabel(column: string, locale: Locale): string {
  const t = translator(locale);
  const key = `rep.col.${column}` as MessageKey;
  // A column added to a report before its label: shown readably rather than
  // as nothing (the dictionary answers undefined for a key it lacks).
  const label = t(key) as string | undefined;

  return label ?? column.replace(/_/g, " ");
}

export function ReportCell({
  column,
  value,
  locale,
  timeZone,
}: {
  column: string;
  value: Cell;
  locale: Locale;
  timeZone: string;
}) {
  const t = translator(locale);

  // Lateness is a three-way fact: late, on time, or no due date to be late
  // against — and the third is not "on time".
  if (column === "late") {
    if (value === true) return <Badge tone="danger">{t("rep.late.yes")}</Badge>;
    if (value === false) return <Badge tone="success">{t("rep.late.no")}</Badge>;

    return <span className="text-caption text-n-400">{t("rep.late.undated")}</span>;
  }

  // Null is an empty cell, not the word "null" and not a zero. Zero is a
  // claim; absent is an absence, and four ADRs turn on the difference.
  if (value === null || value === "") return <span className="text-n-300">—</span>;

  if (column === "reference" && typeof value === "string") {
    return (
      <Link href={`/work/${value}`} className="font-mono text-caption text-a-700 hover:underline">
        {value}
      </Link>
    );
  }

  if (column.endsWith("_at") && typeof value === "string") {
    return <span className="tabular-nums">{formatDateTime(instant(value), timeZone, locale)}</span>;
  }

  if (column === "week_start" && typeof value === "string") {
    // A date, not a moment: shown in no time zone, or the week could slip a day.
    return <span className="tabular-nums">{formatDate(`${value}T00:00:00Z`, "UTC", locale)}</span>;
  }

  if (column === "state_category" && typeof value === "string") {
    return <span>{t(`category.${value}` as MessageKey)}</span>;
  }

  if (column === "utilization") {
    const ratio = Number(value);

    return (
      <span className={ratio > 1 ? "font-medium text-s-danger" : undefined}>
        {new Intl.NumberFormat(INTL_TAG[locale], { style: "percent", maximumFractionDigits: 1 }).format(ratio)}
      </span>
    );
  }

  if (column.endsWith("_hours")) {
    return <span>{formatHours(typeof value === "boolean" ? null : value)}</span>;
  }

  return <>{String(value)}</>;
}

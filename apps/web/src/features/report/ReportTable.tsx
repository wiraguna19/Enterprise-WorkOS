"use client";

import { useCallback } from "react";
import { columnLabel, isNumeric, ReportCell, type Cell } from "./ReportCell";
import { TablePager, TableSearch, useTableView } from "@/features/table/TableControls";
import type { Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

/**
 * A report's rows with a find box and pages.
 *
 * Searched over the text columns as they are shown (reference, title, names,
 * project, department) — not numbers, which a find box is the wrong tool for.
 */
const SEARCHED = new Set(["reference", "title", "project", "department", "name", "assignee"]);

export function ReportTable({
  columns,
  rows,
  locale,
  timeZone,
}: {
  columns: string[];
  rows: Cell[][];
  locale: Locale;
  timeZone: string;
}) {
  const t = translator(locale);

  const text = useCallback(
    (row: Cell[]) =>
      columns
        .map((column, index) => (SEARCHED.has(column) && typeof row[index] === "string" ? row[index] : ""))
        .join(" "),
    [columns],
  );

  const view = useTableView(rows, text);

  return (
    <div className="space-y-3">
      <TableSearch view={view} label={t("table.search.report")} locale={locale} />

      {view.matched === 0 ? (
        <p className="py-6 text-center text-body-sm text-n-500">{t("table.none", { query: view.query.trim() })}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-body-sm">
            <thead>
              <tr className="border-b border-n-200 text-left">
                {columns.map((column) => (
                  <th
                    key={column}
                    scope="col"
                    className={`px-2 py-2 text-micro font-semibold uppercase tracking-[0.04em] text-n-500 ${isNumeric(column) ? "text-right" : ""}`}
                  >
                    {columnLabel(column, locale)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {view.visible.map((row, index) => (
                <tr key={`${view.page}-${index}`} className="border-b border-n-100 last:border-b-0">
                  {row.map((cell, column) => (
                    <td
                      key={columns[column] ?? column}
                      className={`px-2 py-1.5 align-top text-n-900 ${isNumeric(columns[column] ?? "") ? "text-right tabular-nums" : ""}`}
                    >
                      <ReportCell column={columns[column] ?? ""} value={cell} locale={locale} timeZone={timeZone} />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <TablePager view={view} locale={locale} />
    </div>
  );
}

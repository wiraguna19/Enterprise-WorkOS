"use client";

import { useMemo, useState } from "react";
import { Button } from "@/components/ui/Button";
import { INPUT } from "@/components/ui/Field";
import type { Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

/**
 * Find-in-this-table and pages, for tables whose rows are already here.
 *
 * Not the navbar search, and not a replacement for it. The navbar finds ONE
 * record anywhere in the product; this narrows the rows a page has already
 * loaded — a report's window, one person's week. Both are bounded by the
 * page's own question, so filtering and paging them in the browser is exact
 * and costs no round trip; the export still carries every row.
 */
export const PAGE_SIZE = 25;

export function useTableView<T>(rows: T[], text: (row: T) => string, pageSize: number = PAGE_SIZE) {
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(0);

  const filtered = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase();

    return needle === "" ? rows : rows.filter((row) => text(row).toLocaleLowerCase().includes(needle));
  }, [rows, query, text]);

  const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
  const current = Math.min(page, pages - 1);

  return {
    query,
    setQuery: (value: string) => {
      setQuery(value);
      // A new search starts at its first page, or page 4 of 1 shows nothing.
      setPage(0);
    },
    page: current,
    pages,
    setPage,
    total: rows.length,
    matched: filtered.length,
    visible: filtered.slice(current * pageSize, current * pageSize + pageSize),
    pageSize,
  };
}

type View = ReturnType<typeof useTableView<unknown>>;

export function TableSearch({
  view,
  label,
  locale,
}: {
  view: Pick<View, "query" | "setQuery" | "total" | "matched">;
  /** What is being searched, for the placeholder: "Search by reference or title". */
  label: string;
  locale: Locale;
}) {
  const t = translator(locale);

  return (
    <div className="flex flex-wrap items-center gap-3">
      <input
        type="search"
        value={view.query}
        onChange={(event) => view.setQuery(event.target.value)}
        placeholder={label}
        aria-label={label}
        className={INPUT.replace("w-full", "w-72 max-w-full")}
      />
      <span className="text-caption tabular-nums text-n-500" aria-live="polite">
        {view.query.trim() === ""
          ? t.plural("table.rows", view.total)
          : t("table.matched", { matched: view.matched, total: view.total })}
      </span>
    </div>
  );
}

export function TablePager({
  view,
  locale,
}: {
  view: Pick<View, "page" | "pages" | "setPage" | "matched" | "pageSize">;
  locale: Locale;
}) {
  const t = translator(locale);

  if (view.pages <= 1) return null;

  const first = view.page * view.pageSize + 1;
  const last = Math.min(view.matched, first + view.pageSize - 1);

  return (
    <nav aria-label={t("table.pages")} className="flex items-center justify-between gap-3 px-2 py-2">
      <span className="text-caption tabular-nums text-n-500">
        {t("table.range", { first, last, total: view.matched })}
      </span>
      <div className="flex items-center gap-1">
        <Button variant="ghost" size="sm" disabled={view.page === 0} onClick={() => view.setPage(view.page - 1)}>
          {t("table.prev")}
        </Button>
        <span className="px-1 text-caption tabular-nums text-n-500">
          {t("table.page", { page: view.page + 1, pages: view.pages })}
        </span>
        <Button
          variant="ghost"
          size="sm"
          disabled={view.page >= view.pages - 1}
          onClick={() => view.setPage(view.page + 1)}
        >
          {t("table.next")}
        </Button>
      </div>
    </nav>
  );
}

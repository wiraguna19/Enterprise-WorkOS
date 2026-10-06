"use client";

import Link from "next/link";
import { useCallback } from "react";
import { Badge } from "@/components/ui/Badge";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import type { WorkloadItem } from "@/features/people/types";
import {
  PAGE_SIZE,
  TablePager,
  TableSearch,
  useTableView,
} from "@/features/table/TableControls";
import type { Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { formatDate } from "@/lib/format";

/**
 * The items behind one person's committed hours, with a find box once there
 * are more than a page of them, and pages.
 */
export function CommittedWorkTable({
  rows,
  timeZone,
  locale,
  weekStart,
}: {
  rows: WorkloadItem[];
  timeZone: string;
  locale: Locale;
  weekStart: string;
}) {
  const t = translator(locale);
  const text = useCallback(
    (item: WorkloadItem) => `${item.reference} ${item.title}`,
    [],
  );
  const view = useTableView(rows, text);

  return (
    <div>
      {rows.length > PAGE_SIZE && (
        <div className="px-4 py-3">
          <TableSearch
            view={view}
            label={t("table.search.work")}
            locale={locale}
          />
        </div>
      )}
      <DataTable caption={t("wl.caption")}>
        <THead>
          <Tr>
            <Th>{t("wl.col.item")}</Th>
            <Th width="w-36">{t("wl.col.due")}</Th>
            <Th width="w-20" align="right">
              {t("wl.col.hours")}
            </Th>
          </Tr>
        </THead>
        <TBody>
          {view.visible.map((item) => {
            // Due before the week began and not finished: the reason a row sits
            // in the second panel, named on the row.
            const overdue =
              item.due_at !== null && item.due_at.slice(0, 10) < weekStart;

            return (
              <Tr key={item.id}>
                <Td>
                  <Link
                    href={`/work/${item.reference}`}
                    className="group flex min-w-0 items-baseline gap-2"
                  >
                    <span className="w-16 shrink-0 font-mono text-caption text-n-500">
                      {item.reference}
                    </span>
                    <span className="min-w-0 truncate font-medium text-n-900 group-hover:underline">
                      {item.title}
                    </span>
                  </Link>
                </Td>
                <Td muted>
                  <span className="flex items-center gap-1.5 whitespace-nowrap">
                    {item.due_at
                      ? formatDate(item.due_at, timeZone, locale)
                      : t("items.noDue")}
                    {overdue && <Badge tone="danger">{t("wl.overdue")}</Badge>}
                  </span>
                </Td>
                <Td align="right">
                  {/* The contribution, flagged where it is the organization's
                    default rather than anyone's estimate. Marking it on the ROW
                    is what lets a manager tell "this person has 32 committed
                    hours" from "six items nobody has estimated". */}
                  <span
                    className="tabular-nums text-n-700"
                    title={
                      item.counted_at_default
                        ? t("wl.defaultEstimate")
                        : undefined
                    }
                  >
                    {item.share_hours === null
                      ? "—"
                      : t("time.hours", { hours: item.share_hours })}
                    {item.counted_at_default && (
                      <span className="ml-1 text-s-active">*</span>
                    )}
                  </span>
                </Td>
              </Tr>
            );
          })}
        </TBody>
      </DataTable>
      <TablePager view={view} locale={locale} />
    </div>
  );
}

"use client";

import { useCallback } from "react";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { daysLabel, leaveDates, StatusBadge } from "./LeaveBits";
import { LeaveCancel } from "./LeaveDecision";
import type { LeaveRequest } from "./types";
import { TablePager, TableSearch, useTableView } from "@/features/table/TableControls";
import type { Locale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

/** Every request in the window, searchable by person or type, with HR's correction. */
export function AllLeaveTable({ requests, locale }: { requests: LeaveRequest[]; locale: Locale }) {
  const t = translator(locale);
  const text = useCallback((request: LeaveRequest) => `${request.person.name} ${request.type.name}`, []);
  const view = useTableView(requests, text);

  return (
    <div>
      <div className="px-4 py-3">
        <TableSearch view={view} label={t("lv.all.search")} locale={locale} />
      </div>
      <DataTable caption={t("lv.all.title")}>
        <THead>
          <Tr>
            <Th>{t("lv.all.col.person")}</Th>
            <Th>{t("lv.all.col.type")}</Th>
            <Th>{t("lv.all.col.dates")}</Th>
            <Th>{t("lv.all.col.status")}</Th>
            <Th width="w-28">{""}</Th>
          </Tr>
        </THead>
        <TBody>
          {view.visible.map((request) => (
            <Tr key={request.id}>
              <Td>{request.person.name}</Td>
              <Td muted>{request.type.name}</Td>
              <Td muted>{leaveDates(request, locale)} · {daysLabel(request.days, locale)}</Td>
              <Td><StatusBadge status={request.status} step={request.step} locale={locale} /></Td>
              <Td>{(request.status === "pending" || request.status === "approved") && <LeaveCancel id={request.id} />}</Td>
            </Tr>
          ))}
        </TBody>
      </DataTable>
      <TablePager view={view} locale={locale} />
    </div>
  );
}

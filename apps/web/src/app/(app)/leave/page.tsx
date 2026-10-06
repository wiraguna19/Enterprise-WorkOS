import Link from "next/link";
import { notFound } from "next/navigation";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { LeaveCancel } from "@/features/leave/LeaveDecision";
import { BalanceFigures, daysLabel, leaveDates, StatusBadge } from "@/features/leave/LeaveBits";
import { LeaveRequestForm } from "@/features/leave/LeaveRequestForm";
import type { MyLeave } from "@/features/leave/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { formatDate } from "@/lib/format";

/**
 * My leave (ADR 0063): what I have, asking for more, and what I asked for.
 */
export default async function LeavePage() {
  const me = await requireUser();

  if (!me.permissions.includes("leave.request")) notFound();

  const locale = asLocale(me.user.locale);
  const t = translator(locale);
  const { data } = await api<MyLeave>("/leave/me");
  const today = new Date().toISOString().slice(0, 10);

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("nav.leave")}
        description={t("lv.me.description")}
        action={
          <div className="flex gap-3 text-body-sm">
            <Link href="/leave/approvals" className="text-a-700 hover:underline">{t("lv.approvals.title")}</Link>
            {me.permissions.includes("leave.manage") && (
              <Link href="/leave/all" className="text-a-700 hover:underline">{t("lv.all.title")}</Link>
            )}
          </div>
        }
      />

      <PageBody>
        {data.balance === null ? (
          <EmptyState title={t("lv.none.title")} description={t("lv.none.body")} />
        ) : (
          <>
            <Panel
              id="leave-balance"
              title={t("lv.bal.title")}
              description={t("lv.bal.period", {
                from: formatDate(`${data.balance.period_start}T00:00:00Z`, "UTC", locale),
                to: formatDate(`${data.balance.period_end}T00:00:00Z`, "UTC", locale),
              })}
            >
              <BalanceFigures balance={data.balance} locale={locale} />
              {data.balance.probation_ends_on > today && (
                <p className="mt-3 text-caption text-s-active">
                  {t("lv.bal.probation", { date: formatDate(`${data.balance.probation_ends_on}T00:00:00Z`, "UTC", locale) })}
                </p>
              )}
            </Panel>

            <Panel id="leave-ask" title={t("lv.req.title")}>
              <LeaveRequestForm types={data.types} />
            </Panel>
          </>
        )}

        <Panel id="leave-mine" title={t("lv.mine.title")} bleed>
          {data.requests.length === 0 ? (
            <p className="px-4 py-6 text-body-sm text-n-500">{t("lv.mine.empty")}</p>
          ) : (
            <ul className="divide-y divide-n-100">
              {data.requests.map((request) => (
                <li key={request.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-body-sm">
                  <span className="min-w-0 flex-1">
                    <span className="font-medium text-n-900">{request.type.name}</span>
                    <span className="ml-2 text-n-500">{leaveDates(request, locale)} · {daysLabel(request.days, locale)}</span>
                    {request.decision_note && <span className="block text-caption text-n-500">“{request.decision_note}” — {request.decided_by}</span>}
                  </span>
                  <StatusBadge status={request.status} step={request.step} locale={locale} />
                  {(request.status === "pending" || (request.status === "approved" && request.starts_on > today)) && (
                    <LeaveCancel id={request.id} />
                  )}
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </PageBody>
    </div>
  );
}

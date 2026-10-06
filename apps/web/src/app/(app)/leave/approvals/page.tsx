import { notFound } from "next/navigation";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { BalanceFigures, daysLabel, leaveDates, StatusBadge } from "@/features/leave/LeaveBits";
import { LeaveDecision } from "@/features/leave/LeaveDecision";
import type { LeaveRequest } from "@/features/leave/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";

/**
 * Requests waiting for the reader (ADR 0063): their reports' at the manager
 * step and, for HR, everything at the HR step. Each shows what the person
 * would have left, because "approve" is a decision about a balance.
 */
export default async function LeaveApprovalsPage() {
  const me = await requireUser();

  if (!me.permissions.includes("leave.request")) notFound();

  const locale = asLocale(me.user.locale);
  const t = translator(locale);
  const { data } = await api<LeaveRequest[]>("/leave/awaiting");

  return (
    <div className="space-y-5">
      <div className="space-y-3">
        <Breadcrumb locale={locale} items={[{ label: t("nav.leave"), href: "/leave" }, { label: t("lv.approvals.title") }]} />
        <PageHeader title={t("lv.approvals.title")} description={t("lv.approvals.description")} />
      </div>

      <PageBody>
        {data.length === 0 ? (
          <EmptyState title={t("lv.approvals.empty.title")} description={t("lv.approvals.empty.body")} />
        ) : (
          data.map((request) => (
            <Panel
              key={request.id}
              id={`leave-${request.id}`}
              title={`${request.person.name} · ${request.type.name}`}
              description={`${leaveDates(request, locale)} · ${daysLabel(request.days, locale)}`}
              actions={<StatusBadge status={request.status} step={request.step} locale={locale} />}
            >
              <div className="space-y-3">
                {request.reason && <p className="text-body-sm text-n-700">“{request.reason}”</p>}
                {request.document_required && <p className="text-caption text-s-active">{t("lv.req.document")}</p>}
                {request.balance && (
                  <div>
                    <p className="mb-1 text-caption text-n-500">{t("lv.approvals.balance")}</p>
                    <BalanceFigures balance={request.balance} locale={locale} />
                  </div>
                )}
                <LeaveDecision id={request.id} />
              </div>
            </Panel>
          ))
        )}
      </PageBody>
    </div>
  );
}

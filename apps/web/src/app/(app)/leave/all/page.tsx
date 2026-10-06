import Link from "next/link";
import { notFound } from "next/navigation";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { AllLeaveTable } from "@/features/leave/AllLeaveTable";
import type { LeaveRequest } from "@/features/leave/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import type { MessageKey } from "@/i18n/messages/en";

const STATUSES = ["pending", "approved", "rejected", "cancelled"] as const;

/** Every request, for HR (`leave.manage`): see and correct (ADR 0063). */
export default async function AllLeavePage({ searchParams }: { searchParams: Promise<{ status?: string }> }) {
  const [me, query] = await Promise.all([requireUser(), searchParams]);

  if (!me.permissions.includes("leave.manage")) notFound();

  const locale = asLocale(me.user.locale);
  const t = translator(locale);
  const status = STATUSES.find((value) => value === query.status);
  const { data } = await api<LeaveRequest[]>(`/leave/requests${status ? `?status=${status}` : ""}`);

  return (
    <div className="space-y-5">
      <div className="space-y-3">
        <Breadcrumb locale={locale} items={[{ label: t("nav.leave"), href: "/leave" }, { label: t("lv.all.title") }]} />
        <PageHeader title={t("lv.all.title")} description={t("lv.all.description")} />
      </div>

      <PageBody>
        <div className="flex flex-wrap gap-3 text-body-sm">
          <Link href="/leave/all" className={status ? "text-a-700 hover:underline" : "font-medium text-n-900"}>{t("lv.all.any")}</Link>
          {STATUSES.map((value) => (
            <Link key={value} href={`/leave/all?status=${value}`} className={status === value ? "font-medium text-n-900" : "text-a-700 hover:underline"}>
              {t(`lv.status.${value}` as MessageKey)}
            </Link>
          ))}
        </div>

        <Panel id="leave-all" title={t("lv.all.title")} bleed>
          <AllLeaveTable requests={data} locale={locale} />
        </Panel>
      </PageBody>
    </div>
  );
}

import { notFound } from "next/navigation";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { HolidaysPanel } from "@/features/leave/HolidaysPanel";
import { LeavePolicyForm, LeavePresetStart } from "@/features/leave/LeavePolicyForm";
import { LeaveTypesPanel } from "@/features/leave/LeaveTypesPanel";
import type { Holiday, LeaveSettingsData } from "@/features/leave/types";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import type { MessageKey } from "@/i18n/messages/en";

/**
 * The organization's leave rules (ADR 0063): its policy, its leave types and
 * the days it is closed. `notFound()` without `leave.manage`, like every gated
 * settings screen.
 */
export default async function LeaveSettingsPage({
  searchParams,
}: {
  searchParams: Promise<{ year?: string }>;
}) {
  const [me, query] = await Promise.all([requireUser(), searchParams]);

  if (!me.permissions.includes("leave.manage")) notFound();

  const t = translator(asLocale(me.user.locale));
  const year = /^\d{4}$/.test(query.year ?? "") ? Number(query.year) : new Date().getFullYear();

  const [{ data }, { data: holidays }] = await Promise.all([
    api<LeaveSettingsData>("/leave/settings"),
    api<Holiday[]>(`/leave/holidays?year=${year}`),
  ]);

  return (
    <div className="space-y-5">
      <PageHeader title={t("settings.leave.label")} description={t("lv.page.description")} />

      <PageBody>
        {data.warnings.length > 0 && (
          <div role="status" className="rounded-md border border-s-active/40 bg-s-active/10 px-4 py-3 text-body-sm text-n-900">
            <p className="font-medium">{t("lv.warn.title")}</p>
            <ul className="mt-1 list-disc pl-5">
              {data.warnings.map((warning) => (
                <li key={warning.code}>{warningText(warning.code, warning.minimum, t)}</li>
              ))}
            </ul>
            <p className="mt-1 text-caption text-n-500">{t("lv.warn.note")}</p>
          </div>
        )}

        {data.policy === null ? (
          <LeavePresetStart presets={data.presets} />
        ) : (
          <LeavePolicyForm key={data.policy.updated_at} policy={data.policy} />
        )}

        {data.policy !== null && <LeaveTypesPanel types={data.types} />}

        <HolidaysPanel year={year} holidays={holidays} />
      </PageBody>
    </div>
  );
}

function warningText(code: string, minimum: number, t: ReturnType<typeof translator>): string {
  if (code === "base_days_below_minimum") return t("lv.warn.base", { minimum });
  if (code === "sick_uses_quota") return t("lv.warn.sickQuota");

  const missing = code.match(/^type_missing_(\w+)$/);
  if (missing) return t("lv.warn.missing", { type: t(`lv.typeName.${missing[1]}` as MessageKey) ?? missing[1] });

  const short = code.match(/^type_short_(\w+)$/);
  if (short) return t("lv.warn.short", { type: t(`lv.typeName.${short[1]}` as MessageKey) ?? short[1], minimum });

  return code;
}

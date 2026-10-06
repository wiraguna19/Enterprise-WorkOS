import { Badge } from "@/components/ui/Badge";
import type { Locale } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import { translator } from "@/i18n/translate";
import { formatDate } from "@/lib/format";
import type { LeaveBalance, LeaveRequest, LeaveStatus } from "./types";

/** Small pieces shared by the leave screens (ADR 0063). */
export function StatusBadge({ status, step, locale }: { status: LeaveStatus; step?: string | null; locale: Locale }) {
  const t = translator(locale);
  const tone = status === "approved" ? "success" : status === "rejected" ? "danger" : status === "pending" ? "warning" : "neutral";
  const label = status === "pending" && step ? t(`lv.status.pending.${step}` as MessageKey) : t(`lv.status.${status}` as MessageKey);

  return <Badge tone={tone}>{label}</Badge>;
}

/** "7 Okt" or "7 – 9 Okt 2026", with a half day said. Dates are dates: shown in UTC. */
export function leaveDates(request: Pick<LeaveRequest, "starts_on" | "ends_on" | "half_day">, locale: Locale): string {
  const t = translator(locale);
  const from = formatDate(`${request.starts_on}T00:00:00Z`, "UTC", locale);

  if (request.starts_on === request.ends_on) {
    return request.half_day ? `${from} (${t(`lv.half.${request.half_day}` as MessageKey)})` : from;
  }

  return `${from} – ${formatDate(`${request.ends_on}T00:00:00Z`, "UTC", locale)}`;
}

export function daysLabel(days: number, locale: Locale): string {
  return translator(locale).plural("lv.days", days, { count: new Intl.NumberFormat(locale === "id" ? "id-ID" : "en-GB").format(days) });
}

/** A balance in one row of figures, each with what it means. */
export function BalanceFigures({ balance, locale }: { balance: LeaveBalance; locale: Locale }) {
  const t = translator(locale);
  const figure = (label: string, value: number, hint?: string, strong = false) => (
    <div className="min-w-28">
      <div className={`tabular-nums ${strong ? "text-h2 font-semibold text-n-900" : "text-h3 text-n-800"}`}>{value}</div>
      <div className="text-caption text-n-500">{label}</div>
      {hint && <div className="text-micro text-n-400">{hint}</div>}
    </div>
  );

  return (
    <div className="flex flex-wrap gap-6">
      {figure(t("lv.bal.available"), balance.available, undefined, true)}
      {figure(t("lv.bal.earned"), balance.earned, balance.earned < balance.entitlement ? t("lv.bal.of", { total: balance.entitlement }) : undefined)}
      {balance.carried > 0 &&
        figure(
          t("lv.bal.carried"),
          balance.carried,
          balance.carry_expires_on ? t("lv.bal.until", { date: formatDate(`${balance.carry_expires_on}T00:00:00Z`, "UTC", locale) }) : undefined,
        )}
      {figure(t("lv.bal.used"), balance.used)}
      {balance.pending > 0 && figure(t("lv.bal.pending"), balance.pending)}
    </div>
  );
}

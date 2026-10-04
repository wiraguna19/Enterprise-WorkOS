import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageBody } from "@/components/ui/PageBody";
import { Panel } from "@/components/ui/Panel";
import { Timesheet } from "@/features/time/Timesheet";
import type { TimesheetDay, TimesheetMeta } from "@/features/time/types";
import { api } from "@/lib/api";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

/**
 * My timesheet (docs/03 §4, docs/08 §3).
 *
 * Time is logged on the work item, where the context is; it is read back here,
 * by day, which is the shape the question "what did I do last week" has. This
 * page deliberately offers no way to add an entry: an hour logged away from the
 * item it belongs to is an hour with no note worth reading.
 */
export default async function TimePage({
  searchParams,
}: {
  searchParams: Promise<{ from?: string; to?: string }>;
}) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);

  const query = new URLSearchParams();
  if (params.from) query.set("from", params.from);
  if (params.to) query.set("to", params.to);

  const { data: days, meta } = await api<TimesheetDay[]>(`/me/time?${query}`);
  const window = meta as unknown as TimesheetMeta;
  // Translated (ADR 0060).
  const locale = asLocale(me.user.locale);
  const t = translator(locale);
  const summary = t.plural("time.summary", window.days_logged, { hours: window.total_hours });

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("nav.timesheet")}
        description={summary}
      />

      <PageBody>
        {days.length === 0 ? (
          <EmptyState
            title={t("time.none")}
            description={t("time.empty.body")}
          />
        ) : (
          <Panel
            id="timesheet"
            title={t("time.window")}
            description={summary}
            bleed
          >
            <Timesheet days={days} window={window} timeZone={me.user.timezone} locale={locale} />
          </Panel>
        )}
      </PageBody>
    </div>
  );
}

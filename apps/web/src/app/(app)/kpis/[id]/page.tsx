import Link from "next/link";
import { notFound } from "next/navigation";
import { Badge } from "@/components/ui/Badge";
import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { DataTable, TBody, THead, Td, Th, Tr } from "@/components/ui/DataTable";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { ArchiveKpi } from "@/features/kpi/ArchiveKpi";
import { formatKpiValue, periodLabel, sourceLabel, STATUS_TONE, statusLabel, targetSentence } from "@/features/kpi/format";
import { KpiForm } from "@/features/kpi/KpiForm";
import { KpiTile } from "@/features/kpi/KpiTile";
import { RecordValue } from "@/features/kpi/RecordValue";
import type { Kpi } from "@/features/kpi/types";
import { asLocale } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import { translator } from "@/i18n/translate";
import { api, ApiRequestError } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * One KPI: where it stands, how it got there period by period, and — for whoever
 * keeps it — the values to enter and the definition to correct (ADR 0062).
 *
 * The history is a table as well as a line, so every point on the chart can
 * be read as a number.
 */
export default async function KpiPage({ params }: { params: Promise<{ id: string }> }) {
  const [me, { id }] = await Promise.all([requireUser(), params]);
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  const kpi = await api<Kpi>(`/kpis/${id}`)
    .then((r) => r.data)
    .catch((error: unknown) => {
      if (error instanceof ApiRequestError && (error.status === 404 || error.status === 403)) notFound();
      throw error;
    });

  const newestFirst = [...kpi.history].reverse();
  const subjectHref =
    kpi.subject.type === "project" && kpi.subject.key
      ? `/projects/${kpi.subject.key}/overview`
      : kpi.subject.type === "team"
        ? `/teams/${kpi.subject.id}`
        : `/departments`;

  return (
    <div className="space-y-5">
      <Breadcrumb locale={locale} items={[{ label: t("kpi.title"), href: "/kpis" }, { label: kpi.name }]} />

      <PageHeader title={kpi.name} description={kpi.description || undefined} />

      <PageBody>
        <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_1fr]">
          <KpiTile kpi={kpi} t={t} locale={locale} />

          <Panel id="definition" title={t("kpi.definition")}>
            <dl className="grid gap-x-6 gap-y-2 text-body-sm sm:grid-cols-[auto_1fr]">
              <dt className="text-n-500">{t("kpi.about")}</dt>
              <dd>
                <Link href={subjectHref} className="text-n-900 hover:underline">
                  {t(`kpi.subject.${kpi.subject.type}` as MessageKey, { name: kpi.subject.name ?? "—" })}
                </Link>
              </dd>
              <dt className="text-n-500">{t("kpi.form.source")}</dt>
              <dd className="text-n-900">
                {sourceLabel(kpi.source, t)}
                <span className="block text-caption text-n-500">{t(`kpi.sourceHint.${kpi.source}` as MessageKey)}</span>
              </dd>
              <dt className="text-n-500">{t("kpi.form.target")}</dt>
              <dd className="text-n-900">{targetSentence(kpi, t, locale)}</dd>
              <dt className="text-n-500">{t("kpi.statusRule")}</dt>
              <dd className="text-n-700">{t("kpi.statusRuleBody")}</dd>
            </dl>
          </Panel>
        </div>

        <Panel id="history" title={t("kpi.history")} bleed>
          <DataTable caption={t("kpi.history")}>
            <THead>
              <Tr>
                <Th>{t("kpi.col.period")}</Th>
                <Th width="w-32" align="right">{t("kpi.col.value")}</Th>
                <Th width="w-32">{t("kpi.col.status")}</Th>
                <Th>{t("kpi.col.note")}</Th>
              </Tr>
            </THead>
            <TBody>
              {newestFirst.map((point) => (
                <Tr key={point.period_start}>
                  <Td>
                    {periodLabel(kpi.period, point.period_start, t, locale)}
                    {point.partial && <span className="ml-1 text-caption text-n-500">· {t("kpi.soFar")}</span>}
                  </Td>
                  <Td align="right">
                    <span className="tabular-nums">{formatKpiValue(point.value, kpi.unit, t, locale)}</span>
                  </Td>
                  <Td>
                    <Badge tone={STATUS_TONE[point.status]}>{statusLabel(point.status, t)}</Badge>
                  </Td>
                  <Td muted>{point.note ?? ""}</Td>
                </Tr>
              ))}
            </TBody>
          </DataTable>
        </Panel>

        {kpi.can_manage && kpi.source === "manual" && (
          <Panel id="record" title={t("kpi.record.title")} description={t("kpi.record.description")}>
            <RecordValue
              kpiId={kpi.id}
              periods={newestFirst.map((point) => [point.period_start, periodLabel(kpi.period, point.period_start, t, locale)])}
            />
          </Panel>
        )}

        {kpi.can_manage && (
          <Panel id="edit" title={t("kpi.edit")} actions={<ArchiveKpi id={kpi.id} />}>
            <KpiForm existing={kpi} />
          </Panel>
        )}
      </PageBody>
    </div>
  );
}

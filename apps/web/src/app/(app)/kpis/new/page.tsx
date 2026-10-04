import { Breadcrumb } from "@/components/ui/Breadcrumb";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { KpiForm } from "@/features/kpi/KpiForm";
import type { KpiVocabulary } from "@/features/kpi/types";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/** Defining a KPI. The subjects on offer are the ones the API says this person may keep KPIs for. */
export default async function NewKpiPage() {
  const me = await requireUser();
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  const vocabulary = me.permissions.includes("kpi.view")
    ? await api<KpiVocabulary>("/kpis/vocabulary").then((r) => r.data)
    : null;

  return (
    <div className="space-y-5">
      <Breadcrumb locale={locale} items={[{ label: t("kpi.title"), href: "/kpis" }, { label: t("kpi.new") }]} />
      <PageHeader title={t("kpi.new")} description={t("kpi.newDescription")} />

      {vocabulary === null || vocabulary.subjects.length === 0 ? (
        <EmptyState title={t("kpi.cannotKeep.title")} description={t("kpi.cannotKeep.body")} />
      ) : (
        <Panel id="define" title={t("kpi.define")}>
          <KpiForm vocabulary={vocabulary} />
        </Panel>
      )}
    </div>
  );
}

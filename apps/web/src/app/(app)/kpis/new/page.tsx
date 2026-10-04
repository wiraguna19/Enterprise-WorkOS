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
export default async function NewKpiPage({ searchParams }: { searchParams: Promise<{ person?: string }> }) {
  const [me, params] = await Promise.all([requireUser(), searchParams]);
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  // A KPI about one person is set from their page (ADR 0062). Whether this
  // reader may set it is the API's answer for that person, not the list of
  // groups below.
  const person = params.person
    ? await Promise.all([
        api<{ name: string }>(`/people/${params.person}`).then((r) => r.data),
        api<unknown[]>(`/people/${params.person}/kpis`).then((r) => r.meta?.can_manage === true),
      ])
        .then(([detail, canManage]) => (canManage ? { id: params.person ?? "", name: detail.name } : null))
        .catch(() => null)
    : undefined;

  const vocabulary = me.permissions.includes("kpi.view") || person
    ? await api<KpiVocabulary>("/kpis/vocabulary").then((r) => r.data).catch(() => null)
    : null;

  const allowed = person === undefined ? (vocabulary?.subjects.length ?? 0) > 0 : person !== null && vocabulary !== null;

  return (
    <div className="space-y-5">
      <Breadcrumb
        locale={locale}
        items={
          person
            ? [
                { label: t("nav.people"), href: "/people" },
                { label: person.name, href: `/people/${person.id}` },
                { label: t("kpi.new") },
              ]
            : [{ label: t("kpi.title"), href: "/kpis" }, { label: t("kpi.new") }]
        }
      />
      <PageHeader
        title={t("kpi.new")}
        description={person ? t("kpi.newPersonDescription", { name: person.name }) : t("kpi.newDescription")}
      />

      {!allowed || vocabulary === null ? (
        <EmptyState title={t("kpi.cannotKeep.title")} description={t("kpi.cannotKeep.body")} />
      ) : (
        <Panel id="define" title={t("kpi.define")}>
          <KpiForm vocabulary={vocabulary} person={person ?? undefined} />
        </Panel>
      )}
    </div>
  );
}

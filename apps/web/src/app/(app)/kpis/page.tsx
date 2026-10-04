import { notFound } from "next/navigation";
import { ButtonLink } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { KpiTile } from "@/features/kpi/KpiTile";
import type { Kpi, KpiSubjectType, KpiVocabulary } from "@/features/kpi/types";
import { asLocale } from "@/i18n/config";
import type { MessageKey } from "@/i18n/messages/en";
import { translator } from "@/i18n/translate";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

/**
 * The KPIs of departments, teams and projects (ADR 0062), grouped by what they
 * are about. Never a table of people: a KPI about one person is not listed
 * here or anywhere across people.
 */
export default async function KpisPage() {
  const me = await requireUser();
  const locale = asLocale(me.user.locale);
  const t = translator(locale);

  // Hidden from the nav without the permission, and refused here too — 404,
  // like every other refusal: whether the page exists is not this one's to say.
  if (!me.permissions.includes("kpi.view")) notFound();

  const [kpis, vocabulary, mine] = await Promise.all([
    api<Kpi[]>("/kpis").then((r) => r.data),
    api<KpiVocabulary>("/kpis/vocabulary").then((r) => r.data),
    // The reader's OWN KPIs, if anyone has set them — the one person KPI
    // list this page may show, because it is about the person reading it.
    api<Kpi[]>(`/people/${me.membership.id}/kpis`)
      .then((r) => r.data)
      .catch(() => [] as Kpi[]),
  ]);

  const groups = (["department", "team", "project"] as KpiSubjectType[])
    .map((type) => ({ type, kpis: kpis.filter((kpi) => kpi.subject.type === type) }))
    .filter((group) => group.kpis.length > 0);

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("kpi.title")}
        description={t("kpi.description")}
        action={
          vocabulary.subjects.length > 0 ? (
            <ButtonLink href="/kpis/new" variant="primary">
              {t("kpi.new")}
            </ButtonLink>
          ) : undefined
        }
      />

      {mine.length > 0 && (
        <Panel id="kpis-mine" title={t("kpi.mine.title")} description={t("kpi.mine.description")}>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {mine.map((kpi) => (
              <KpiTile key={kpi.id} kpi={kpi} t={t} locale={locale} />
            ))}
          </div>
        </Panel>
      )}

      {groups.length === 0 ? (
        <EmptyState
          title={t("kpi.empty.title")}
          description={t("kpi.empty.body")}
          action={
            vocabulary.subjects.length > 0 ? (
              <ButtonLink href="/kpis/new" variant="primary">
                {t("kpi.new")}
              </ButtonLink>
            ) : undefined
          }
        />
      ) : (
        groups.map((group) => (
          <Panel key={group.type} id={`kpis-${group.type}`} title={t(`kpi.group.${group.type}` as MessageKey)}>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {group.kpis.map((kpi) => (
                <KpiTile key={kpi.id} kpi={kpi} t={t} locale={locale} />
              ))}
            </div>
          </Panel>
        ))
      )}
    </div>
  );
}

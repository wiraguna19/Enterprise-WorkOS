import { ButtonLink } from "@/components/ui/Button";
import { Panel } from "@/components/ui/Panel";
import type { Locale } from "@/i18n/config";
import type { Translator } from "@/i18n/translate";
import { KpiTile } from "./KpiTile";
import type { Kpi } from "./types";

/**
 * One person's KPIs, on their own page (ADR 0062, "Per person").
 *
 * Rendered only when the API answered — which it does for the person and for
 * the people above them in the reporting line, and to nobody else. There is
 * deliberately no other place these appear together with anyone else's.
 */
export function PersonKpis({
  membershipId,
  name,
  kpis,
  canManage,
  self,
  t,
  locale,
}: {
  membershipId: string;
  name: string;
  kpis: Kpi[];
  canManage: boolean;
  /** The reader is the person: worded to them. */
  self: boolean;
  t: Translator;
  locale: Locale;
}) {
  return (
    <Panel
      id="person-kpis"
      title={t("kpi.person.title")}
      description={self ? t("kpi.person.descriptionSelf") : t("kpi.person.description", { name })}
      actions={
        canManage ? (
          <ButtonLink href={`/kpis/new?person=${membershipId}`} size="sm">
            {t("kpi.person.new")}
          </ButtonLink>
        ) : undefined
      }
    >
      {kpis.length === 0 ? (
        <p className="text-body-sm text-n-500">{self ? t("kpi.person.noneSelf") : t("kpi.person.none")}</p>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {kpis.map((kpi) => (
            <KpiTile key={kpi.id} kpi={kpi} t={t} locale={locale} />
          ))}
        </div>
      )}
    </Panel>
  );
}

import { notFound } from "next/navigation";
import { KeyValue, KeyValueItem } from "@/components/ui/KeyValue";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { ArchivePolicyForm } from "@/features/organization/ArchivePolicyForm";
import { MfaPolicyForm } from "@/features/organization/MfaPolicyForm";
import { SessionPolicyForm } from "@/features/organization/SessionPolicyForm";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { minutesName } from "@/i18n/labels";

/**
 * The organization itself, and the one policy it can set (ADR 0028).
 *
 * `organization.view` has gated the Settings entry in the nav since Phase 1
 * while no route, policy or service on the server had ever asked about it —
 * an interface enforcing something the API had never heard of, which
 * `EveryPermissionMeansSomethingTest` calls the least visible defect of the
 * lot. This page is the first thing behind it that the server also refuses.
 *
 * Gated here as well as at the door: a nav entry whose target refuses reads as
 * a broken product, and a page whose target does not reads as an unbuilt one.
 * `notFound` rather than a 403 screen, for the same reason the API answers 404
 * for somebody else's session — the existence of a screen is itself something
 * not everybody is owed.
 */
type Settings = {
  id: string;
  name: string;
  slug: string;
  session_lifetime_days: number;
  idle_timeout_minutes: number | null;
  require_mfa: boolean;
  people_without_mfa: number;
  archive_closed_after_days: number | null;
};

export default async function OrganizationSettingsPage() {
  const me = await requireUser();

  if (!me.permissions.includes("organization.view")) notFound();

  const t = translator(asLocale(me.user.locale));

  const { data } = await api<Settings>("/organization/settings");

  return (
    <div className="space-y-5">
      <PageHeader
        title={t("settings.organization.label")}
        description={t("org.description")}
      />

      <PageBody>
        <Panel id="profile" title={t("org.profile")} description={t("org.profile.description")}>
          <KeyValue columns={3}>
            <KeyValueItem label={t("projects.col.name")}>{data.name}</KeyValueItem>
            <KeyValueItem label={t("org.slug")}>{data.slug}</KeyValueItem>
            <KeyValueItem label={t("org.sessionsLast")}>
              {t.plural("unit.days", data.session_lifetime_days)}
            </KeyValueItem>
            <KeyValueItem label={t("org.idleTimeout")}>
              {data.idle_timeout_minutes === null
                ? t("org.none")
                : minutesName(data.idle_timeout_minutes, t)}
            </KeyValueItem>
          </KeyValue>
        </Panel>

        <MfaPolicyForm
          required={data.require_mfa}
          peopleWithout={data.people_without_mfa}
          editable={me.permissions.includes("organization.manage_settings")}
        />

        <SessionPolicyForm
          current={data.session_lifetime_days}
          currentIdle={data.idle_timeout_minutes}
          editable={me.permissions.includes("organization.manage_settings")}
        />

        <ArchivePolicyForm
          current={data.archive_closed_after_days}
          editable={me.permissions.includes("organization.manage_settings")}
        />
      </PageBody>
    </div>
  );
}

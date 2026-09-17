import { notFound } from "next/navigation";
import { KeyValue, KeyValueItem } from "@/components/ui/KeyValue";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { MfaPolicyForm } from "@/features/organization/MfaPolicyForm";
import { SessionPolicyForm } from "@/features/organization/SessionPolicyForm";
import { api } from "@/lib/api";
import { requireUser } from "@/lib/auth";

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
};

export default async function OrganizationSettingsPage() {
  const me = await requireUser();

  if (!me.permissions.includes("organization.view")) notFound();

  const { data } = await api<Settings>("/organization/settings");

  return (
    <div className="space-y-5">
      <PageHeader
        title="Organization"
        description="What this organization is, and how long it lets people stay signed in."
      />

      <PageBody>
        <Panel id="profile" title="Profile" description="Read-only for now — nothing in the product changes a name or a slug yet.">
          <KeyValue columns={3}>
            <KeyValueItem label="Name">{data.name}</KeyValueItem>
            <KeyValueItem label="Slug">{data.slug}</KeyValueItem>
            <KeyValueItem label="Sessions last">
              {data.session_lifetime_days} {data.session_lifetime_days === 1 ? "day" : "days"}
            </KeyValueItem>
            <KeyValueItem label="Idle timeout">
              {data.idle_timeout_minutes === null
                ? "None"
                : describeIdle(data.idle_timeout_minutes)}
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
      </PageBody>
    </div>
  );
}

/** Minutes are what the API stores; hours and days are what people say. */
function describeIdle(minutes: number): string {
  if (minutes % 1440 === 0) {
    const days = minutes / 1440;

    return `${days} ${days === 1 ? "day" : "days"}`;
  }

  if (minutes % 60 === 0) {
    const hours = minutes / 60;

    return `${hours} ${hours === 1 ? "hour" : "hours"}`;
  }

  return `${minutes} minutes`;
}
